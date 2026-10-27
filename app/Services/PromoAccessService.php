<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Promo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PromoAccessService
{
    public function __construct(
        private OrganizationScopeService $organizationScope,
        private WorkspaceAccessService $workspaceAccess,
        private SalesTeamScopeService $salesTeamScope,
    ) {}

    public function allowedBranches(User $user): Collection
    {
        if ($user->isSuperadmin()) {
            if (! $user->hasPermission('promo.manage')) {
                return collect();
            }

            return Branch::query()->where('is_active', true)->forDropdown()->get();
        }

        if ($user->hasPrimaryRole(['admin', 'branch_manager']) && $user->hasPermission('promo.manage')) {
            return $this->workspaceAccess->accessibleBranches($user)
                ->filter(fn (Branch $branch): bool => $this->workspaceAccess->canEditBranch($user, $branch))
                ->values();
        }

        if (! $user->hasPrimaryRole('sales_coordinator') || ! $user->hasScopedPermission('sales_pocketbook', 'manage')) {
            return collect();
        }

        $scopeBranchIds = $this->organizationScope->branchIds($user, 'sales_pocketbook', 'manage');
        $workspaceBranchIds = $this->workspaceAccess->accessibleBranchIds($user);

        $salesIds = $this->salesTeamScope->currentSales($user)->pluck('id');
        $teamBranchIds = Branch::query()->whereHas('projects', fn (Builder $query) => $query
            ->where('is_active', true)
            ->whereHas('assignedUsers', fn (Builder $assignments) => $assignments
                ->whereIn('users.id', $salesIds)
                ->where('project_user.is_active', true)
                ->where(fn (Builder $dates) => $dates->whereNull('project_user.assignment_start_date')->orWhereDate('project_user.assignment_start_date', '<=', today()))
                ->where(fn (Builder $dates) => $dates->whereNull('project_user.assignment_end_date')->orWhereDate('project_user.assignment_end_date', '>=', today()))))
            ->pluck('id')->all();
        $projectBranchIds = Branch::query()->whereHas('projects', fn (Builder $query) => $query
            ->whereIn('id', $this->organizationScope->projectIds($user, 'sales_pocketbook', 'manage')))->pluck('id')->all();
        $branchIds = array_intersect($teamBranchIds, $scopeBranchIds, $projectBranchIds, $workspaceBranchIds);

        return Branch::query()->where('is_active', true)->whereIn('id', $branchIds)->forDropdown()->get();
    }

    public function canManageBranch(User $user, int|Branch|null $branch): bool
    {
        if ($branch === null) {
            return $user->isSuperadmin() && $user->hasPermission('promo.manage');
        }

        $branchId = $branch instanceof Branch ? $branch->id : (int) $branch;

        if (! $this->allowedBranches($user)->contains('id', $branchId)) {
            return false;
        }

        if ($user->hasPrimaryRole(['admin', 'branch_manager']) && $user->hasPermission('promo.manage')) {
            return $this->workspaceAccess->canEditBranch($user, $branchId);
        }

        return $user->hasPrimaryRole('sales_coordinator')
            || $user->isSuperadmin()
            || ($this->workspaceAccess->canViewBranch($user, $branchId) && $this->workspaceAccess->canEditBranch($user, $branchId));
    }

    public function visibleQuery(User $user): Builder
    {
        $query = Promo::query();

        return $user->isSuperadmin() && $user->hasPermission('promo.manage')
            ? $query
            : $query->whereIn('branch_id', $this->allowedBranches($user)->pluck('id'));
    }
}
