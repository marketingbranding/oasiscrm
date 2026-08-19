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
        'sales_pocketbook', 'work_planner', 'database', 'database_v2', 'consumer_progress', 'bridge_fund', 'expenses',
    ];

    public function __construct(
        private WorkspaceAccessService $workspaceAccess,
        private ReportingHierarchyService $hierarchy,
    ) {}

    /** @return array<int> */
    public function visibleUserIds(User $viewer, ?string $module = null, string $action = 'view'): array
    {
        $scopes = $this->scopes($viewer, $module, $action);
        if (in_array('all', $scopes, true)) {
            return User::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $ids = in_array('own', $scopes, true) ? [(int) $viewer->id] : [];
        if (in_array('team', $scopes, true)) {
            $ids = [...$ids, ...$this->teamIds($viewer)];
        }
        if (in_array('branch', $scopes, true)) {
            $branchIds = $this->branchIds($viewer, $module, $action);
            $ids = [...$ids, ...$this->authorizedUserIdsForBranches($branchIds)];
        }
        if (in_array('assigned', $scopes, true)) {
            $projectIds = $this->projectIds($viewer, $module, $action);
            $ids = [...$ids, ...$this->currentUserIdsForProjects($projectIds)];
        }

        return collect($ids)->map(fn ($id) => (int) $id)->unique()->values()->all();
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
        $scopes = $this->scopes($viewer, $module, $action);
        if (in_array('all', $scopes, true)) {
            return Branch::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $ids = [];
        if (array_intersect($scopes, ['own', 'team', 'branch']) !== []) {
            $ids = $this->workspaceAccess->accessibleBranchIds($viewer);
        }
        if (in_array('assigned', $scopes, true)) {
            $ids = [...$ids, ...LeadMaster::query()->whereIn('id', $this->currentAssignedProjectIds($viewer))->pluck('branch_id')->all()];
        }

        return collect($ids)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return array<int> */
    public function projectIds(User $viewer, ?string $module = null, string $action = 'view'): array
    {
        $scopes = $this->scopes($viewer, $module, $action);
        if (in_array('all', $scopes, true)) {
            return LeadMaster::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $ids = [];
        if (in_array('branch', $scopes, true)) {
            $ids = LeadMaster::query()
                ->where('is_active', true)
                ->whereIn('branch_id', $this->workspaceAccess->accessibleBranchIds($viewer))
                ->pluck('id')->all();
        }
        if (array_intersect($scopes, ['own', 'assigned']) !== []) {
            $ids = [...$ids, ...$this->currentAssignedProjectIds($viewer)];
        }
        if (in_array('team', $scopes, true)) {
            $teamProjectIds = $this->currentProjectIdsForUsers($this->teamIds($viewer));
            $authorizedProjectIds = LeadMaster::query()
                ->where('is_active', true)
                ->whereIn('branch_id', $this->workspaceAccess->accessibleBranchIds($viewer))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            $ids = [...$ids, ...array_intersect(
                $teamProjectIds,
                [...$authorizedProjectIds, ...$this->currentAssignedProjectIds($viewer)],
            )];
        }

        return collect($ids)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return array<int> */
    public function teamIds(User $viewer): array
    {
        $descendantIds = $this->hierarchy->descendantIds($viewer);
        if ($descendantIds === []) {
            return [];
        }

        $branchIds = $this->workspaceAccess->accessibleBranchIds($viewer);
        $projectIds = $this->currentAssignedProjectIds($viewer);
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
    }

    private function scopes(User $viewer, ?string $module, string $action): array
    {
        if ($viewer->isSuperadmin()) {
            return ['all'];
        }

        $modules = $module ? [$module] : self::MODULES;

        return collect(['all', 'branch', 'assigned', 'team', 'own'])
            ->filter(fn (string $scope) => collect($modules)->contains(
                fn (string $candidate) => $viewer->hasPermission("{$candidate}.{$action}_{$scope}"),
            ))
            ->values()->all();
    }

    private function currentAssignedProjectIds(User $user): array
    {
        return $this->currentProjectIdsForUsers([$user->id]);
    }

    private function currentProjectIdsForUsers(array $userIds): array
    {
        $today = today()->toDateString();

        return DB::table('project_user')
            ->join('lead_master', 'lead_master.id', '=', 'project_user.project_id')
            ->whereIn('project_user.user_id', $userIds)
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
