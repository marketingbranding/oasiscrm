<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrganizationScopeService
{
    private const MODULES = [
        'sales_pocketbook', 'work_planner', 'database', 'consumer_progress', 'bridge_fund', 'expenses',
    ];

    public function __construct(
        private WorkspaceAccessService $workspaceAccess,
        private ReportingHierarchyService $hierarchy,
        private SalesTeamScopeService $salesTeamScope,
        private OrganizationGraphService $graph,
        private OrganizationGraphModeService $mode,
        private OrganizationGraphShadowService $shadow,
    ) {}

    private array $scopeCache = [];

    private function cached(string $key, callable $resolver): array
    {
        if (array_key_exists($key, $this->scopeCache)) {
            return $this->scopeCache[$key];
        }

        return $this->scopeCache[$key] = $resolver();
    }

    /** @return array<int> */
    public function visibleUserIds(User $viewer, ?string $module = null, string $action = 'view'): array
    {
        $workspaceBranchIds = $this->workspaceBranchIds($viewer);

        return $this->cached('users:'.$viewer->id.':'.$module.':'.$action.':'.implode(',', $workspaceBranchIds), function () use ($viewer, $module, $action, $workspaceBranchIds): array {
            $scopes = $this->scopes($viewer, $module, $action);
            if (in_array('all', $scopes, true) && ! $this->workspaceAccess->isBranchScopedOperator($viewer)) {
                return User::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
            }

            if (in_array('all', $scopes, true)) {
                return $this->authorizedUserIdsForBranches($workspaceBranchIds);
            }

            if ($this->isSupervisorSalesScope($viewer, $module)) {
                $ids = in_array('own', $scopes, true) ? [(int) $viewer->id] : [];
                if (in_array('team', $scopes, true)) {
                    $ids = [...$ids, ...$this->teamIds($viewer, $module)];
                }

                return collect($ids)->map(fn ($id) => (int) $id)->unique()->values()->all();
            }

            $ids = in_array('own', $scopes, true) ? [(int) $viewer->id] : [];
            if (in_array('team', $scopes, true)) {
                $ids = [...$ids, ...$this->teamIds($viewer, $module)];
            }
            if (in_array('branch', $scopes, true)) {
                $branchIds = $this->branchIds($viewer, $module, $action);
                $ids = [...$ids, ...$this->authorizedUserIdsForBranches($branchIds)];
            }
            if (in_array('assigned', $scopes, true)) {
                $projectIds = $this->projectIds($viewer, $module, $action);
                $ids = [...$ids, ...$this->currentUserIdsForProjects($projectIds)];
            }
            if (in_array('project', $scopes, true)) {
                $projectIds = $this->projectIds($viewer, $module, $action);
                $ids = [...$ids, ...$this->currentUserIdsForProjects($projectIds)];
            }

            return collect($ids)->map(fn ($id) => (int) $id)->unique()->values()->all();
        });
    }

    public function visibleUsersQuery(User $viewer, ?string $module = null, string $action = 'view'): Builder
    {
        return User::query()->whereIn('id', $this->visibleUserIds($viewer, $module, $action));
    }

    public function visibleUsers(User $viewer, ?string $module = null, string $action = 'view'): Collection
    {
        return $this->visibleUsersQuery($viewer, $module, $action)->get();
    }

    /** @return array<int> */
    public function branchIds(User $viewer, ?string $module = null, string $action = 'view'): array
    {
        $workspaceBranchIds = $this->workspaceBranchIds($viewer);

        return $this->cached('branch:'.$viewer->id.':'.$module.':'.$action.':'.implode(',', $workspaceBranchIds), function () use ($viewer, $module, $action, $workspaceBranchIds): array {
            $scopes = $this->scopes($viewer, $module, $action);
            if (in_array('all', $scopes, true) && ! $this->workspaceAccess->isBranchScopedOperator($viewer)) {
                return Branch::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
            }

            $ids = [];
            if (array_intersect($scopes, ['own', 'team', 'branch']) !== []) {
                $ids = $workspaceBranchIds;
            }
            if ($action === 'view' && in_array('own', $scopes, true)) {
                $ids = [...$ids, ...$this->workspaceAccess->historicalBranchIds($viewer)];
            }
            if (array_intersect($scopes, ['assigned', 'project']) !== []) {
                $ids = [...$ids, ...LeadMaster::query()->whereIn('id', $this->currentAssignedProjectIds($viewer, $workspaceBranchIds))->pluck('branch_id')->all()];
            }

            return collect($ids)->map(fn ($id) => (int) $id)->unique()->values()->all();
        });
    }

    /** @return array<int> */
    public function projectIds(User $viewer, ?string $module = null, string $action = 'view'): array
    {
        $workspaceBranchIds = $this->workspaceBranchIds($viewer);

        return $this->cached('project:'.$viewer->id.':'.$module.':'.$action.':'.implode(',', $workspaceBranchIds), function () use ($viewer, $module, $action, $workspaceBranchIds): array {
            $scopes = $this->scopes($viewer, $module, $action);
            if (in_array('all', $scopes, true) && ! $this->workspaceAccess->isBranchScopedOperator($viewer)) {
                return LeadMaster::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
            }

            if (in_array('all', $scopes, true)) {
                return LeadMaster::query()
                    ->where('is_active', true)
                    ->whereIn('branch_id', $workspaceBranchIds)
                    ->pluck('id')->map(fn ($id) => (int) $id)->all();
            }

            $ids = [];
            $branchScopeApplies = ! $this->isSupervisorSalesScope($viewer, $module);
            if ($branchScopeApplies && in_array('branch', $scopes, true)) {
                $ids = LeadMaster::query()
                    ->where('is_active', true)
                    ->whereIn('branch_id', $workspaceBranchIds)
                    ->pluck('id')->all();
            }
            if ($action === 'view' && in_array('own', $scopes, true)) {
                $ids = [...$ids, ...LeadMaster::query()
                    ->where('is_active', true)
                    ->whereIn('branch_id', $this->workspaceAccess->historicalBranchIds($viewer))
                    ->pluck('id')->all()];
            }
            if (array_intersect($scopes, ['own', 'assigned', 'project']) !== []) {
                $ids = [...$ids, ...$this->currentAssignedProjectIds($viewer, $workspaceBranchIds)];
            }
            if (in_array('team', $scopes, true)) {
                $teamProjectIds = $this->currentProjectIdsForUsers($this->teamIds($viewer, $module));
                $assignedProjectIds = $this->currentAssignedProjectIds($viewer, $workspaceBranchIds);
                $authorizedProjectIds = LeadMaster::query()
                    ->where('is_active', true)
                    ->whereIn('branch_id', $workspaceBranchIds)
                    ->pluck('id')->map(fn ($id) => (int) $id)->all();
                $ids = [...$ids, ...array_intersect(
                    $teamProjectIds,
                    [...$assignedProjectIds, ...$authorizedProjectIds],
                )];
            }

            return collect($ids)->map(fn ($id) => (int) $id)->unique()->values()->all();
        });
    }

    public function requiresProjectScope(User $viewer, string $module, string $action = 'view'): bool
    {
        $scopes = $this->scopes($viewer, $module, $action);

        if ($this->isSupervisorSalesScope($viewer, $module)) {
            return true;
        }

        return array_intersect($scopes, ['all', 'branch']) === []
            && array_intersect($scopes, ['own', 'team', 'assigned', 'project']) !== [];
    }

    public function hasScope(User $viewer, string $module, string $action, string $scope): bool
    {
        return in_array($scope, $this->scopes($viewer, $module, $action), true);
    }

    public function allowsProjectRecord(User $viewer, string $module, string $action, int $branchId, ?int $projectId): bool
    {
        if (! in_array($branchId, $this->branchIds($viewer, $module, $action), true)) {
            return false;
        }

        return ! $this->requiresProjectScope($viewer, $module, $action)
            || ($projectId !== null && in_array($projectId, $this->projectIds($viewer, $module, $action), true));
    }

    /** @return array<int> */
    public function hierarchyIds(User $viewer): array
    {
        return $this->cached('hierarchy:'.$viewer->id, function () use ($viewer): array {
            $ids = $this->hierarchy->descendantIds($viewer);
            $effectiveMode = $this->mode->modeFor($viewer, $viewer->branch_id);
            $canonical = collect([...$ids, ...$this->graph->ancestors($viewer)->pluck('id')->all()])
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            if ($effectiveMode === 'graph') {
                return $canonical;
            }
            if ($effectiveMode === 'shadow') {
                $legacy = $this->legacyHierarchyIds($viewer, $ids);
                $this->shadow->record('hierarchy_ids', $legacy === $canonical);

                return $legacy;
            }

            return $this->legacyHierarchyIds($viewer, $ids);
        });
    }

    /** @param array<int> $ids @return array<int> */
    private function legacyHierarchyIds(User $viewer, array $ids): array
    {
        $supervisorId = $viewer->supervisor_user_id;
        while ($supervisorId) {
            if (in_array((int) $supervisorId, $ids, true)) {
                break;
            }
            $ids[] = (int) $supervisorId;
            $supervisorId = User::query()->whereKey($supervisorId)->value('supervisor_user_id');
        }

        return collect($ids)->unique()->values()->all();
    }

    /** @return array<int> */
    public function teamIds(User $viewer, ?string $module = null): array
    {
        return $this->cached('team:'.$viewer->id.':'.$module, function () use ($viewer, $module): array {
            if ($module === 'sales_pocketbook' && $viewer->hasPrimaryRole('sales_coordinator')) {
                return $this->salesTeamScope->currentSalesQuery($viewer)
                    ->pluck('users.id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();
            }

            if ($this->isSupervisorSalesScope($viewer, $module)) {
                $team = $this->salesTeamScope->forWorkspace(
                    $viewer,
                    $this->workspaceBranchIds($viewer),
                    $this->workspaceAccess->accessibleProjectIds($viewer),
                    includeHistorical: false,
                );

                return collect([$team['coordinators'], $team['sales']])
                    ->flatten(1)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();
            }

            $descendantIds = $this->hierarchy->descendantIds($viewer);
            if ($descendantIds === []) {
                return [];
            }

            $branchIds = $this->workspaceBranchIds($viewer);
            $projectIds = $this->currentAssignedProjectIds($viewer, $branchIds);
            $projectUserIds = $this->currentUserIdsForProjects($projectIds);
            $branchUserIds = $this->authorizedUserIdsForBranches($branchIds);

            return User::query()
                ->whereIn('id', $descendantIds)
                ->where('is_active', true)
                ->where(function (Builder $query) use ($branchUserIds, $projectUserIds) {
                    $query->whereIn('id', $branchUserIds);
                    if ($projectUserIds !== []) {
                        $query->orWhereIn('id', $projectUserIds);
                    }
                })
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        });
    }

    private function workspaceBranchIds(User $user): array
    {
        return $this->cached('workspace:'.$user->id, fn () => $this->workspaceAccess->accessibleBranchIds($user));
    }

    private function isSupervisorSalesScope(User $viewer, ?string $module): bool
    {
        return $module === 'sales_pocketbook' && $viewer->hasPrimaryRole('supervisor');
    }

    private function scopes(User $viewer, ?string $module, string $action): array
    {
        if ($viewer->isSuperadmin()) {
            return ['all'];
        }

        $modules = $module ? [$module] : self::MODULES;

        return collect(['all', 'branch', 'assigned', 'project', 'team', 'own'])
            ->filter(fn (string $scope) => collect($modules)->contains(
                fn (string $candidate) => $viewer->hasPermission("{$candidate}.{$action}_{$scope}"),
            ))
            ->values()->all();
    }

    private function currentAssignedProjectIds(User $user, array $workspaceBranchIds): array
    {
        $workspaceBranchIds = collect($workspaceBranchIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
        $key = 'assigned:'.$user->id.':'.implode(',', $workspaceBranchIds);

        return $this->cached($key, fn () => $this->currentProjectIdsForUsers([$user->id], $workspaceBranchIds));
    }

    private function currentProjectIdsForUsers(array $userIds, ?array $workspaceBranchIds = null): array
    {
        $today = today()->toDateString();

        return DB::table('project_user')
            ->join('lead_master', 'lead_master.id', '=', 'project_user.project_id')
            ->whereIn('project_user.user_id', $userIds)
            ->when($workspaceBranchIds !== null, fn ($query) => $query->whereIn('lead_master.branch_id', $workspaceBranchIds))
            ->where('project_user.is_active', true)
            ->where('lead_master.is_active', true)
            ->where(fn ($query) => $query->whereNull('assignment_start_date')->orWhereDate('assignment_start_date', '<=', $today))
            ->where(fn ($query) => $query->whereNull('assignment_end_date')->orWhereDate('assignment_end_date', '>=', $today))
            ->pluck('project_user.project_id')->map(fn ($id) => (int) $id)->all();
    }

    private function currentUserIdsForProjects(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $today = today()->toDateString();

        return DB::table('project_user')
            ->join('users', 'users.id', '=', 'project_user.user_id')
            ->join('lead_master', 'lead_master.id', '=', 'project_user.project_id')
            ->whereIn('project_user.project_id', $projectIds)
            ->where('project_user.is_active', true)
            ->where('users.is_active', true)
            ->where('lead_master.is_active', true)
            ->where(fn ($query) => $query->whereNull('assignment_start_date')->orWhereDate('assignment_start_date', '<=', $today))
            ->where(fn ($query) => $query->whereNull('assignment_end_date')->orWhereDate('assignment_end_date', '>=', $today))
            ->pluck('project_user.user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function authorizedUserIdsForBranches(array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        $membershipIds = DB::table('branch_user')
            ->join('users', 'users.id', '=', 'branch_user.user_id')
            ->whereIn('branch_user.branch_id', $branchIds)
            ->where('branch_user.is_current', true)
            ->where('branch_user.can_view', true)
            ->where('users.is_active', true)
            ->pluck('branch_user.user_id');
        $legacyPrimaryIds = User::query()
            ->where('is_active', true)
            ->whereIn('branch_id', $branchIds)
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('branch_user')
                ->whereColumn('branch_user.user_id', 'users.id')
                ->whereColumn('branch_user.branch_id', 'users.branch_id'))
            ->pluck('id');

        return $membershipIds->merge($legacyPrimaryIds)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
