<?php

namespace App\Services;

use App\Models\OrganizationAssignment;
use App\Models\SalesCoordinatorSales;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class OrganizationBackfillService
{
    public function __construct(private readonly OrganizationGraphService $graph) {}

    /** @return array<string, mixed> */
    public function run(bool $dryRun = true): array
    {
        $users = User::query()->with('role')->orderBy('id')->get();
        $coordinatorAssignments = SalesCoordinatorSales::query()->current()->get()->groupBy('sales_user_id');
        $legacyParents = [];
        $report = [
            'dry_run' => $dryRun,
            'users_scanned' => $users->count(),
            'assignments_proposed' => 0,
            'created' => 0,
            'already_migrated' => 0,
            'conflicts' => 0,
            'multiple_active_coordinator_assignments' => 0,
            'cycles' => 0,
            'invalid_role_relationships' => 0,
            'missing_users' => 0,
            'inactive_parents' => 0,
            'cross_scope_anomalies' => 0,
            'details' => [],
        ];

        foreach ($users as $user) {
            $legacyParents[$user->id] = $this->legacyParent($user, $coordinatorAssignments, $report);
        }
        $cycleUsers = $this->legacyCycleUsers($legacyParents);

        foreach ($users as $user) {
            $parent = $legacyParents[$user->id];
            if ($parent === false) {
                continue;
            }
            if ($parent === null) {
                continue;
            }
            if (isset($cycleUsers[$user->id]) || isset($cycleUsers[$parent->id])) {
                $report['cycles']++;
                $this->detail($report, $user, 'Relasi legacy membentuk siklus organisasi.', $parent);

                continue;
            }

            $current = OrganizationAssignment::query()->where('user_id', $user->id)->current()->first();
            if ($current !== null && (int) $current->parent_user_id === (int) $parent->id) {
                $report['already_migrated']++;

                continue;
            }
            if ($current !== null) {
                $this->conflict($report, $user, 'canonical_assignment_differs', $parent);

                continue;
            }

            try {
                $this->graph->validateParent($user, $parent);
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->first() ?? 'Validasi assignment gagal.';
                if (str_contains($message, 'siklus')) {
                    $report['cycles']++;
                } elseif (str_contains($message, 'Relasi peran') || str_contains($message, 'Tingkat kewenangan')) {
                    $report['invalid_role_relationships']++;
                } elseif (str_contains($message, 'cabang atau proyek')) {
                    $report['cross_scope_anomalies']++;
                } elseif (str_contains($message, 'aktif')) {
                    $report['inactive_parents']++;
                } else {
                    $report['conflicts']++;
                }
                $this->detail($report, $user, $message, $parent);

                continue;
            }

            $report['assignments_proposed']++;
            if ($dryRun) {
                continue;
            }

            $this->graph->assign(
                $user,
                $parent,
                source: 'legacy_backfill',
                reason: 'Backfill idempoten dari struktur organisasi legacy.',
            );
            $report['created']++;
        }

        return $report;
    }

    /** @param Collection<int, Collection<int, SalesCoordinatorSales>> $coordinatorAssignments */
    private function legacyParent(User $user, Collection $coordinatorAssignments, array &$report): User|false|null
    {
        $coordinators = $coordinatorAssignments->get($user->id, collect());
        if ($user->hasPrimaryRole('sales') && $coordinators->count() > 1) {
            $report['multiple_active_coordinator_assignments']++;
            $this->detail($report, $user, 'Sales memiliki lebih dari satu coordinator aktif.', null);

            return false;
        }

        $coordinator = $coordinators->first()?->coordinator;
        $legacySupervisor = $user->supervisor_user_id === null
            ? null
            : User::query()->with('role')->find($user->supervisor_user_id);
        if ($user->supervisor_user_id !== null && $legacySupervisor === null) {
            $report['missing_users']++;
            $this->detail($report, $user, 'Supervisor legacy tidak ditemukan.', null);

            return false;
        }
        if ($coordinator !== null && $legacySupervisor !== null && ! $coordinator->is($legacySupervisor)) {
            $report['conflicts']++;
            $this->detail($report, $user, 'Coordinator Sales dan supervisor legacy berbeda.', $coordinator);

            return false;
        }

        return $coordinator ?? $legacySupervisor;
    }

    /**
     * @param  array<int, User|false|null>  $parents
     * @return array<int, true>
     */
    private function legacyCycleUsers(array $parents): array
    {
        $cycleUsers = [];

        foreach (array_keys($parents) as $userId) {
            $path = [];
            $positions = [];
            $currentId = (int) $userId;

            while (($parent = $parents[$currentId] ?? null) instanceof User) {
                if (isset($positions[$currentId])) {
                    foreach (array_slice($path, $positions[$currentId]) as $cycleUserId) {
                        $cycleUsers[$cycleUserId] = true;
                    }

                    break;
                }

                $positions[$currentId] = count($path);
                $path[] = $currentId;
                $currentId = (int) $parent->id;
            }
        }

        return $cycleUsers;
    }

    /** @param array<string, mixed> $report */
    private function conflict(array &$report, User $user, string $reason, User $parent): void
    {
        $report['conflicts']++;
        $this->detail($report, $user, $reason, $parent);
    }

    /** @param array<string, mixed> $report */
    private function detail(array &$report, User $user, string $reason, ?User $parent): void
    {
        $report['details'][] = [
            'user_id' => $user->id,
            'parent_user_id' => $parent?->id,
            'reason' => $reason,
        ];
    }
}
