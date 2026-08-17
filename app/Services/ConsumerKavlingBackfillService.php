<?php

namespace App\Services;

use App\Models\ConsumerApplication;
use App\Models\ConsumerKavlingAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ConsumerKavlingBackfillService
{
    /**
     * @return Collection<int, array{application_id: int, branch_id: int, branch_name: string, project_id: int, project_name: string, kavling_id: int, kavling_code: string, intended_status: string, classification: string, reason: string}>
     */
    public function preview(?int $branchId = null, ?int $projectId = null): Collection
    {
        return $this->collectCandidates($branchId, $projectId);
    }

    public function execute(?int $branchId = null, ?int $projectId = null): array
    {
        $candidates = $this->collectCandidates($branchId, $projectId);

        $inserts = $candidates->filter(fn (array $row) => in_array($row['classification'], ['READY_RESERVED', 'READY_SOLD'], true))->values();

        $created = 0;
        foreach ($inserts as $row) {
            $inserted = DB::transaction(function () use ($row) {
                $application = ConsumerApplication::query()->lockForUpdate()->find($row['application_id']);
                if ($application === null || $application->deleted_at !== null) {
                    return false;
                }

                $exists = ConsumerKavlingAssignment::query()
                    ->where('consumer_application_id', $application->id)
                    ->where('kavling_id', $row['kavling_id'])
                    ->whereIn('assignment_status', ['active', 'sold'])
                    ->whereNull('released_at')
                    ->exists();

                if ($exists) {
                    return false;
                }

                $occupied = ConsumerKavlingAssignment::query()
                    ->where('kavling_id', $row['kavling_id'])
                    ->where('consumer_application_id', '!=', $application->id)
                    ->whereIn('assignment_status', ['active', 'sold'])
                    ->whereNull('released_at')
                    ->exists();

                if ($occupied) {
                    return false;
                }

                ConsumerKavlingAssignment::create([
                    'consumer_application_id' => $application->id,
                    'kavling_id' => $row['kavling_id'],
                    'assigned_at' => Carbon::parse($application->akad_date ?? now()),
                    'assignment_status' => $row['intended_status'],
                ]);

                return true;
            });

            if ($inserted) {
                $created++;
            }
        }

        return [
            'total_candidates' => $candidates->count(),
            'created' => $created,
            'skipped' => $candidates->count() - $created,
        ];
    }

    private function collectCandidates(?int $branchId, ?int $projectId): Collection
    {
        $applications = ConsumerApplication::query()
            ->whereNotNull('kavling_id')
            ->whereNull('deleted_at')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->with(['branch:id,name', 'project:id,project_name', 'kavling:id,kavling_code'])
            ->get();

        $existingAssignments = ConsumerKavlingAssignment::query()
            ->whereIn('assignment_status', ['active', 'sold'])
            ->whereNull('released_at')
            ->get()
            ->keyBy(fn (ConsumerKavlingAssignment $a) => "{$a->consumer_application_id}_{$a->kavling_id}");

        $kavlingOwnership = ConsumerKavlingAssignment::query()
            ->whereIn('assignment_status', ['active', 'sold'])
            ->whereNull('released_at')
            ->get()
            ->groupBy('kavling_id')
            ->mapWithKeys(fn ($assignments, $kavlingId) => [
                $kavlingId => $assignments->pluck('consumer_application_id')->unique()->values()->all(),
            ]);

        $applicationsByKavling = $applications->groupBy('kavling_id')
            ->mapWithKeys(fn ($apps, $kavlingId) => [
                $kavlingId => $apps->pluck('id')->unique()->values()->all(),
            ]);

        $akadApplications = $applications->pluck('id')->chunk(50)->flatten()->all();
        $akadStageAppIds = DB::table('consumer_stage_events')
            ->whereIn('consumer_application_id', $akadApplications)
            ->whereIn('stage', ['akad', 'bast'])
            ->pluck('consumer_application_id')
            ->unique()
            ->all();
        $akadStageSet = array_flip($akadStageAppIds);

        $results = collect();
        foreach ($applications as $application) {
            $results->push($this->classifyApplication(
                $application,
                $existingAssignments,
                $kavlingOwnership,
                $applicationsByKavling,
                $akadStageSet,
            ));
        }

        return $results;
    }

    private function classifyApplication(
        ConsumerApplication $application,
        Collection $existingAssignments,
        Collection $kavlingOwnership,
        Collection $applicationsByKavling,
        array $akadStageSet,
    ): array {
        $kavlingId = (int) $application->kavling_id;
        $key = "{$application->id}_{$kavlingId}";

        $base = [
            'application_id' => $application->id,
            'branch_id' => $application->branch_id,
            'branch_name' => $application->branch?->name ?? '-',
            'project_id' => $application->project_id,
            'project_name' => $application->project?->project_name ?? '-',
            'kavling_id' => $kavlingId,
            'kavling_code' => $application->kavling?->kavling_code ?? '-',
        ];

        if ($existingAssignments->has($key)) {
            $existing = $existingAssignments->get($key);

            return array_merge($base, [
                'intended_status' => $existing->assignment_status,
                'classification' => 'ALREADY_BACKFILLED',
                'reason' => "Assignment exists ({$existing->assignment_status})",
            ]);
        }

        if ($application->consumer_status === 'Mundur') {
            return array_merge($base, [
                'intended_status' => '-',
                'classification' => 'SKIPPED',
                'reason' => 'Consumer sudah Mundur',
            ]);
        }

        $otherAppsOnKavling = ($applicationsByKavling->get($kavlingId) ?? []);
        $otherApps = array_filter($otherAppsOnKavling, fn (int $id) => $id !== $application->id);
        if (count($otherApps) > 0) {
            return array_merge($base, [
                'intended_status' => '-',
                'classification' => 'CONFLICT',
                'reason' => count($otherApps).' aplikasi lain juga menunjuk kavling ini',
            ]);
        }

        $otherOwners = $kavlingOwnership->get($kavlingId, []);
        $otherOwners = array_filter($otherOwners, fn (int $id) => $id !== $application->id);
        if (count($otherOwners) > 0) {
            return array_merge($base, [
                'intended_status' => '-',
                'classification' => 'CONFLICT',
                'reason' => 'Kavling sudah memiliki assignment aktif dari aplikasi lain',
            ]);
        }

        if ($this->hasReachedAkad($application, $akadStageSet)) {
            return array_merge($base, [
                'intended_status' => 'sold',
                'classification' => 'READY_SOLD',
                'reason' => 'Aplikasi sudah mencapai Akad/BAST',
            ]);
        }

        if ($application->consumer_status === 'Reject') {
            return array_merge($base, [
                'intended_status' => 'active',
                'classification' => 'READY_RESERVED',
                'reason' => 'Reject tetap mempertahankan reservasi',
            ]);
        }

        return array_merge($base, [
            'intended_status' => 'active',
            'classification' => 'READY_RESERVED',
            'reason' => 'Aplikasi aktif dengan kavling',
        ]);
    }

    private function hasReachedAkad(ConsumerApplication $application, array $akadStageSet): bool
    {
        if ($application->current_stage === 'akad') {
            return true;
        }

        if ($application->akad_date !== null) {
            return true;
        }

        return isset($akadStageSet[$application->id]);
    }
}
