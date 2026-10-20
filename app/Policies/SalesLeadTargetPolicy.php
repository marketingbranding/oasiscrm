<?php

namespace App\Policies;

use App\Models\SalesLeadTarget;
use App\Models\User;
use App\Services\SalesTeamScopeService;
use App\Services\WorkspaceAccessService;

class SalesLeadTargetPolicy
{
    public function __construct(
        private readonly SalesTeamScopeService $salesTeam,
        private readonly WorkspaceAccessService $workspace,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->active($user) && collect([
            'sales_targets.view_own',
            'sales_targets.view_team',
            'sales_targets.view_branch',
            'sales_targets.view_all',
        ])->contains(fn (string $permission): bool => $user->hasPermission($permission));
    }

    public function view(User $user, SalesLeadTarget $salesLeadTarget): bool
    {
        $sales = $salesLeadTarget->sales;
        if (! $sales || ! $this->viewAny($user) || ! $this->workspace->canViewBranch($user, (int) $sales->branch_id)) {
            return false;
        }
        if ($user->hasPermission('sales_targets.view_all')) {
            return true;
        }
        if ($user->isSales()) {
            return $user->hasPermission('sales_targets.view_own') && (int) $sales->id === (int) $user->id;
        }
        if ($user->hasPrimaryRole('sales_coordinator')) {
            return $user->hasPermission('sales_targets.view_team') && $this->salesTeam->contains($user, (int) $sales->id);
        }

        return $user->hasPermission('sales_targets.view_branch');
    }

    public function create(User $user): bool
    {
        return $this->active($user) && $user->hasPermission('sales_targets.manage_all') && $user->isSuperadmin();
    }

    public function update(User $user, SalesLeadTarget $salesLeadTarget): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, SalesLeadTarget $salesLeadTarget): bool
    {
        return $this->update($user, $salesLeadTarget);
    }

    private function active(User $user): bool
    {
        return $user->isAccountActive() && $user->is_active;
    }
}
