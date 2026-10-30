<?php

namespace App\Services;

use App\Models\OrganizationAssignment;
use App\Models\SalesCoordinatorSales;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationBackfillService
{
    public function __construct(private readonly OrganizationGraphService $graph) {}

    /** @return array<string, mixed> */
    public function run(bool $dryRun = true): array
    {
        return $this->runForUsers(null, $dryRun);
    }

    /** @return array<string, mixed> */
    public function runControlled(array $userIds, bool $write = false, bool $confirmIsolated = false): array
    {
        $userIds = collect($userIds)->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->sort()->values()->all();
        $reasons = [];
        if ($userIds === []) {
            $reasons[] = 'explicit_selection_required';
        }
        if ($write && ! $confirmIsolated) {
            $reasons[] = 'confirm_isolated_required';
        }
        if ($write && ! (bool) config('organization.backfill_isolated', false)) {
            $reasons[] = 'isolated_marker_required';
        }
        if ($write && ! in_array((string) config('app.env'), ['local', 'testing'], true)) {
            $reasons[] = 'unsafe_environment';
        }
        if ($write && (string) config('database.default') === 'production') {
            $reasons[] = 'production_database';
        }

        $beforeCount = OrganizationAssignment::query()->count();
        if ($reasons !== []) {
            return [
                'dry_run' => ! $write,
                'safety' => [
                    'allowed' => false,
                    'reasons' => $reasons,
                    'selected_user_ids' => $userIds,
                ],
                'loss_analysis' => ['proposals' => [], 'details' => []],
                'proposals' => [],
                'details' => [],
                'before' => ['assignment_count' => $beforeCount],
                'after' => ['assignment_count' => $beforeCount],
                'assignments_proposed' => 0,
                'created' => 0,
                'already_migrated' => 0,
                'conflicts' => 0,
                'idempotency' => ['eligible' => false, 'reason' => 'safety_guard_blocked'],
            ];
        }

        $report = $this->runForUsers($userIds, ! $write);
        $afterCount = OrganizationAssignment::query()->count();

        return [
            ...$report,
            'safety' => [
                'allowed' => true,
                'reasons' => [],
                'selected_user_ids' => $userIds,
            ],
            'loss_analysis' => [
                'proposals' => $report['proposals'],
                'details' => $report['details'],
            ],
            'before' => ['assignment_count' => $beforeCount],
            'after' => ['assignment_count' => $afterCount],
            'idempotency' => [
                'eligible' => true,
                'created_this_run' => $report['created'],
                'equivalent_assignments' => $report['already_migrated'],
            ],
        ];
    }

    /** @param array<int>|null $userIds @return array<string, mixed> */
    private function runForUsers(?array $userIds, bool $dryRun): array
    {
        $users = User::query()
            ->with('role')
            ->when($userIds !== null, fn ($query) => $query->whereIn('id', $userIds))
            ->orderBy('id')
            ->get();
        $coordinatorAssignments = SalesCoordinatorSales::query()
            ->current()
            ->with(['coordinator.role', 'sales.role'])
            ->get()
            ->groupBy('sales_user_id');
        $legacyParents = $this->legacySupervisors($users);
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
            'proposals' => [],
        ];
        $cycleUsers = $this->legacyCycleUsers($legacyParents);

        $process = function () use ($users, $legacyParents, $cycleUsers, $coordinatorAssignments, &$report, $dryRun): void {
            foreach ($users as $user) {
                $this->backfillReporting(
                    $user,
                    $legacyParents[$user->id] ?? null,
                    $cycleUsers,
                    $report,
                    $dryRun,
                );
                $this->backfillSalesTeam(
                    $user,
                    $coordinatorAssignments->get($user->id, collect()),
                    $report,
                    $dryRun,
                );
            }
        };

        if ($dryRun) {
            $process();
        } else {
            DB::transaction($process, attempts: 3);
        }

        return $report;
    }

    /** @return array<int, User|false|null> */
    private function legacySupervisors(Collection $users): array
    {
        $parentIds = $users->pluck('supervisor_user_id')->filter()->unique()->values();
        $parents = User::query()->with('role')->whereIn('id', $parentIds)->get()->keyBy('id');

        return $users->mapWithKeys(function (User $user) use ($parents): array {
            if ($user->supervisor_user_id === null) {
                return [$user->id => null];
            }

            return [$user->id => $parents->get($user->supervisor_user_id) ?? false];
        })->all();
    }

    /** @param array<int, User|false|null> $legacyParents */
    private function backfillReporting(
        User $user,
        User|false|null $parent,
        array $cycleUsers,
        array &$report,
        bool $dryRun,
    ): void {
        if (! $parent instanceof User) {
            if ($parent === false) {
                $report['missing_users']++;
                $this->detail($report, $user, 'Supervisor legacy tidak ditemukan.', null, [
                    'relationship_type' => OrganizationAssignment::REPORTS_TO,
                    'classification' => 'LEGACY_ORPHANED',
                    'severity' => 'UNKNOWN',
                    'confidence' => 'HIGH',
                    'remediation_status' => 'UNRESOLVED',
                ]);
            }

            return;
        }

        if (isset($cycleUsers[$user->id]) || isset($cycleUsers[$parent->id])) {
            $report['cycles']++;
            $this->detail($report, $user, 'Relasi legacy membentuk siklus organisasi.', $parent, [
                'relationship_type' => OrganizationAssignment::REPORTS_TO,
            ]);

            return;
        }

        $current = OrganizationAssignment::query()
            ->where('user_id', $user->id)
            ->current()
            ->first();
        if ($current !== null && (int) $current->parent_user_id === (int) $parent->id) {
            $report['already_migrated']++;

            return;
        }
        if ($current !== null) {
            $this->conflict($report, $user, 'canonical_reporting_assignment_differs', $parent, OrganizationAssignment::REPORTS_TO);

            return;
        }

        try {
            $this->graph->validateParent($user, $parent);
        } catch (ValidationException $exception) {
            $this->recordValidationFailure($report, $user, $parent, $exception, OrganizationAssignment::REPORTS_TO);

            return;
        }

        $report['assignments_proposed']++;
        $this->proposal($report, $user, $parent, OrganizationAssignment::REPORTS_TO);
        if ($dryRun) {
            return;
        }

        $this->graph->assign(
            $user,
            $parent,
            source: 'legacy_backfill',
            reason: 'Backfill idempoten dari supervisor legacy.',
        );
        $report['created']++;
    }

    /** @param Collection<int, SalesCoordinatorSales> $assignments */
    private function backfillSalesTeam(User $sales, Collection $assignments, array &$report, bool $dryRun): void
    {
        if ($assignments->isEmpty()) {
            return;
        }

        if (! $sales->hasPrimaryRole('sales')) {
            $report['invalid_role_relationships']++;
            $this->detail($report, $sales, 'Mapping legacy Sales Coordinator membutuhkan primary role sales.', null, [
                'relationship_type' => OrganizationAssignment::SALES_TEAM_MEMBER_OF,
                'classification' => 'INVALID_ROLE_RELATIONSHIP',
                'severity' => 'STRUCTURAL',
                'confidence' => 'HIGH',
                'remediation_status' => 'UNRESOLVED',
            ]);

            return;
        }

        if ($assignments->count() > 1) {
            $report['multiple_active_coordinator_assignments']++;
            $this->detail($report, $sales, 'Sales memiliki lebih dari satu coordinator aktif.', null, [
                'relationship_type' => OrganizationAssignment::SALES_TEAM_MEMBER_OF,
                'classification' => 'MULTIPLE_ACTIVE_SALES_COORDINATORS',
                'severity' => 'STRUCTURAL',
                'confidence' => 'UNKNOWN',
                'remediation_status' => 'UNRESOLVED',
            ]);

            return;
        }

        $mapping = $assignments->first();
        $coordinator = $mapping?->coordinator;
        if (! $coordinator instanceof User) {
            $report['missing_users']++;
            $this->detail($report, $sales, 'Coordinator legacy tidak ditemukan.', null, [
                'relationship_type' => OrganizationAssignment::SALES_TEAM_MEMBER_OF,
                'classification' => 'LEGACY_ORPHANED',
                'severity' => 'UNKNOWN',
                'confidence' => 'HIGH',
                'remediation_status' => 'UNRESOLVED',
            ]);

            return;
        }

        $currentAssignments = OrganizationAssignment::query()
            ->where('user_id', $sales->id)
            ->currentSalesTeam()
            ->get();
        if ($currentAssignments->count() > 1) {
            $report['conflicts']++;
            $this->detail($report, $sales, 'Terdapat lebih dari satu assignment Sales-team aktif.', $coordinator, [
                'relationship_type' => OrganizationAssignment::SALES_TEAM_MEMBER_OF,
                'classification' => 'AMBIGUOUS_CURRENT_ASSIGNMENTS',
                'severity' => 'SECURITY_CRITICAL',
                'confidence' => 'HIGH',
                'remediation_status' => 'UNRESOLVED',
            ]);

            return;
        }
        $current = $currentAssignments->first();
        if ($current !== null && (int) $current->parent_user_id === (int) $coordinator->id) {
            $report['already_migrated']++;

            return;
        }
        if ($current !== null) {
            $this->conflict($report, $sales, 'canonical_sales_team_assignment_differs', $coordinator, OrganizationAssignment::SALES_TEAM_MEMBER_OF);

            return;
        }

        try {
            $this->graph->validateSalesTeamMember($sales, $coordinator);
        } catch (ValidationException $exception) {
            $this->recordValidationFailure($report, $sales, $coordinator, $exception, OrganizationAssignment::SALES_TEAM_MEMBER_OF);

            return;
        }

        $report['assignments_proposed']++;
        $this->proposal($report, $sales, $coordinator, OrganizationAssignment::SALES_TEAM_MEMBER_OF);
        if ($dryRun) {
            return;
        }

        $this->graph->assignSalesTeamMember(
            $sales,
            $coordinator,
            source: 'legacy_backfill',
            reason: 'Backfill idempoten dari sales_coordinator_sales.',
        );
        $report['created']++;
    }

    /** @param array<int, User|false|null> $parents @return array<int, true> */
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
    private function conflict(array &$report, User $user, string $reason, User $parent, string $relationshipType): void
    {
        $report['conflicts']++;
        $this->detail($report, $user, $reason, $parent, [
            'relationship_type' => $relationshipType,
            'classification' => $relationshipType === OrganizationAssignment::REPORTS_TO
                ? 'REPORTING_RELATIONSHIP_CONFLICT'
                : 'SALES_COORDINATOR_MAPPING',
            'severity' => 'STRUCTURAL',
            'confidence' => 'UNKNOWN',
            'remediation_status' => 'UNRESOLVED',
        ]);
    }

    /** @param array<string, mixed> $report */
    private function recordValidationFailure(
        array &$report,
        User $user,
        User $parent,
        ValidationException $exception,
        string $relationshipType,
    ): void {
        $message = collect($exception->errors())->flatten()->first() ?? 'Validasi assignment gagal.';
        if (str_contains($message, 'siklus')) {
            $report['cycles']++;
        } elseif (str_contains($message, 'Relasi peran') || str_contains($message, 'primary role') || str_contains($message, 'Tingkat kewenangan')) {
            $report['invalid_role_relationships']++;
        } elseif (str_contains($message, 'cabang atau proyek') || str_contains($message, 'tree organisasi') || str_contains($message, 'cabang utama')) {
            $report['cross_scope_anomalies']++;
        } elseif (str_contains($message, 'aktif')) {
            $report['inactive_parents']++;
        } else {
            $report['conflicts']++;
        }
        $this->detail($report, $user, $message, $parent, [
            'relationship_type' => $relationshipType,
            'classification' => 'VALIDATION_FAILURE',
            'severity' => 'STRUCTURAL',
            'confidence' => 'HIGH',
            'remediation_status' => 'UNRESOLVED',
        ]);
    }

    /** @param array<string, mixed> $report */
    private function detail(array &$report, User $user, string $reason, ?User $parent, array $overrides = []): void
    {
        $relationshipType = $overrides['relationship_type'] ?? OrganizationAssignment::REPORTS_TO;
        $current = $relationshipType === OrganizationAssignment::SALES_TEAM_MEMBER_OF
            ? OrganizationAssignment::query()->where('user_id', $user->id)->currentSalesTeam()->first()
            : OrganizationAssignment::query()->where('user_id', $user->id)->current()->first();
        $report['details'][] = array_merge([
            'user_id' => $user->id,
            'parent_user_id' => $parent?->id,
            'reason' => $reason,
            ...$this->evidence($user, $parent, $current, $relationshipType),
        ], $overrides);
    }

    /** @param array<string, mixed> $report */
    private function proposal(array &$report, User $user, User $parent, string $relationshipType): void
    {
        $current = $relationshipType === OrganizationAssignment::SALES_TEAM_MEMBER_OF
            ? OrganizationAssignment::query()->where('user_id', $user->id)->currentSalesTeam()->first()
            : OrganizationAssignment::query()->where('user_id', $user->id)->current()->first();
        $report['proposals'][] = array_merge([
            'user_id' => $user->id,
            'parent_user_id' => $parent->id,
            'relationship_type' => $relationshipType,
            'classification' => 'MISSING_GRAPH_ASSIGNMENT',
            'severity' => 'ACCESS_LOSS',
            'confidence' => 'HIGH',
            'recommended_resolution' => 'REVALIDATE_AND_ADD_CANONICAL_ASSIGNMENT',
            'remediation_status' => 'PROPOSED',
        ], $this->evidence($user, $parent, $current, $relationshipType));
    }

    /** @return array<string, mixed> */
    private function evidence(User $user, ?User $parent, ?OrganizationAssignment $current, string $relationshipType): array
    {
        $projectIds = DB::table('project_user')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('assignment_start_date')->orWhereDate('assignment_start_date', '<=', today()))
            ->where(fn ($query) => $query->whereNull('assignment_end_date')->orWhereDate('assignment_end_date', '>=', today()))
            ->orderBy('project_id')
            ->pluck('project_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return [
            'role' => $user->role?->slug,
            'parent_role' => $parent?->role?->slug,
            'branch_id' => $user->branch_id,
            'branch_code' => $user->branch?->code,
            'parent_branch_id' => $parent?->branch_id,
            'project_ids' => $projectIds,
            'legacy_relationship_active' => $parent !== null,
            'canonical_assignment_active' => $current !== null,
            'canonical_graph_parent_user_id' => $current?->parent_user_id,
            'historical_only' => false,
            'hierarchy_impact' => $relationshipType === OrganizationAssignment::REPORTS_TO
                ? 'legacy_to_graph_reporting_relationship'
                : 'legacy_to_graph_sales_team_relationship',
            'affected_modules' => $this->affectedModules($user),
        ];
    }

    /** @return array<int, string> */
    private function affectedModules(User $user): array
    {
        return $user->hasPrimaryRole(['sales', 'sales_coordinator'])
            ? ['sales_pocketbook', 'consumer_progress', 'reporting']
            : ['sales_pocketbook', 'consumer_progress', 'work_planner', 'database', 'bridge_fund', 'expenses', 'reporting', 'organization'];
    }
}
