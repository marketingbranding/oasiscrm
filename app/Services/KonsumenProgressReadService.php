<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\LeadMaster;
use Illuminate\Support\Facades\Log;
use Throwable;

class KonsumenProgressReadService
{
    public function __construct(
        private readonly KonsumenPipelineService $legacy,
    ) {}

    /** @return array{pipeline: array<string, array<int, array<string, mixed>>>, source: string, fallback_used: bool} */
    public function read(Branch $branch, ?LeadMaster $project = null, ?string $sourceOverride = null): array
    {
        $source = $sourceOverride ?? config('oasis.consumer_progress_read_source', 'legacy');
        if ($source !== 'local') {
            return ['pipeline' => $this->legacy->buildPipeline($branch), 'source' => 'legacy', 'fallback_used' => false];
        }

        try {
            return ['pipeline' => $this->localPipeline($branch, $project), 'source' => 'local', 'fallback_used' => false];
        } catch (Throwable $exception) {
            Log::warning('Konsumen Progress local read fell back to legacy.', [
                'branch_id' => $branch->id,
                'project_id' => $project?->id,
                'exception' => get_class($exception),
                'reason' => substr(preg_replace('/[\r\n]+/', ' ', $exception->getMessage()), 0, 200),
            ]);

            return ['pipeline' => $this->legacy->buildPipeline($branch), 'source' => 'legacy', 'fallback_used' => true];
        }
    }

    private function localPipeline(Branch $branch, ?LeadMaster $project = null): array
    {
        $pipeline = array_fill_keys(array_keys(KonsumenPipelineService::STAGES), []);
        $applications = ConsumerApplication::query()
            ->with(['customer', 'sales', 'project', 'kavling', 'promo', 'stageEvents', 'bankProcesses'])
            ->where('branch_id', $branch->id)
            ->when($project, fn ($query) => $query->where('project_id', $project->id))
            ->get();

        foreach ($applications as $application) {
            $stage = $this->stage($application);
            if (! $stage || ! isset($pipeline[$stage]) || ! $application->customer) {
                continue;
            }
            $bank = $application->bankProcesses->sort(function ($left, $right): int {
                return [
                    $right->submitted_at?->timestamp ?? PHP_INT_MIN,
                    $right->updated_at?->timestamp ?? PHP_INT_MIN,
                    $right->id,
                ] <=> [
                    $left->submitted_at?->timestamp ?? PHP_INT_MIN,
                    $left->updated_at?->timestamp ?? PHP_INT_MIN,
                    $left->id,
                ];
            })->first();

            $pipeline[$stage][] = [
                'id_kavling' => $application->kavling?->kavling_code,
                'kavling' => $application->kavling?->kavling_code ?: $application->kavling?->name ?: '—',
                'nama' => $application->customer->name,
                'nama_konsumen' => $application->customer->name,
                'phone' => $application->customer->phone,
                'project_name' => $application->project?->project_name,
                'sales' => $application->sales?->name,
                'promo' => $application->promo?->name,
                'current_stage' => $stage,
                'current_stage_label' => KonsumenPipelineService::STAGES[$stage],
                'status' => $application->application_status,
                'booking_date' => $application->booking_date?->format('Y-m-d'),
                'akad_date' => $application->akad_date?->format('Y-m-d'),
                'bank' => $bank?->bank_name,
                'status_bank' => $bank?->status,
                'branch' => $branch->name,
                'source_module' => 'Konsumen Progress',
                'source_sheet' => $stage,
            ];
        }

        return $pipeline;
    }

    private function stage(ConsumerApplication $application): ?string
    {
        $pipeline = app(KonsumenPipelineService::class);
        $stage = $pipeline->canonicalStage($application->current_stage);
        if ($stage !== null) {
            return $stage;
        }

        return $application->stageEvents
            ->sort(function ($left, $right): int {
                return [$right->occurred_at?->timestamp ?? PHP_INT_MIN, $right->id]
                    <=> [$left->occurred_at?->timestamp ?? PHP_INT_MIN, $left->id];
            })
            ->map(fn ($event) => $pipeline->canonicalStage($event->stage))
            ->first(fn (?string $stage) => $stage !== null);
    }
}

// Allowed rollout values live in config/oasis.php; invalid values stay on legacy.
