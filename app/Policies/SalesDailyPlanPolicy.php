<?php

namespace App\Policies;

use App\Models\SalesDailyPlan;
use App\Models\User;
use App\Services\SalesTeamScopeService;
use App\Services\WorkspaceAccessService;

class SalesDailyPlanPolicy
{
    public function __construct(
        private readonly SalesTeamScopeService $salesTeam,
        private readonly WorkspaceAccessService $workspace,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->active($user) && collect([
            'sales_daily_plans.view_own',
            'sales_daily_plans.view_team',
            'sales_daily_plans.view_branch',
            'sales_daily_plans.view_all',
        ])->contains(fn (string $permission): bool => $user->hasPermission($permission));
    }

    public function view(User $user, SalesDailyPlan $salesDailyPlan): bool
    {
        if (! $this->viewAny($user) || ! $this->workspace->canViewBranch($user, (int) $salesDailyPlan->branch_id)) {
            return false;
        }

        if ($user->hasPermission('sales_daily_plans.view_all')) {
            return true;
        }

        if ($user->isSales()) {
            return $user->hasPermission('sales_daily_plans.view_own')
                && (int) $salesDailyPlan->sales_user_id === (int) $user->id;
        }

        if ($user->hasPrimaryRole('sales_coordinator')) {
            return $user->hasPermission('sales_daily_plans.view_team')
                && $this->salesTeam->contains($user, (int) $salesDailyPlan->sales_user_id);
        }

        return $user->hasPermission('sales_daily_plans.view_branch');
    }

    public function create(User $user): bool
    {
        return $this->active($user)
            && $user->isSales()
            && $user->hasPermission('sales_daily_plans.manage_own')
            && filled($user->branch_id)
            && $this->workspace->canViewBranch($user, (int) $user->branch_id);
    }

    public function update(User $user, SalesDailyPlan $salesDailyPlan): bool
    {
        if (! $this->view($user, $salesDailyPlan)) {
            return false;
        }

        if ($user->isSales()) {
            return $salesDailyPlan->status === 'draft'
                && $user->hasPermission('sales_daily_plans.manage_own')
                && (int) $salesDailyPlan->sales_user_id === (int) $user->id;
        }

        return $salesDailyPlan->status === 'submitted'
            && $user->hasPrimaryRole('sales_coordinator')
            && $user->hasPermission('sales_daily_plans.manage_team')
            && $this->salesTeam->contains($user, (int) $salesDailyPlan->sales_user_id);
    }

    public function delete(User $user, SalesDailyPlan $salesDailyPlan): bool
    {
        return $this->update($user, $salesDailyPlan);
    }

    public function submit(User $user, SalesDailyPlan $salesDailyPlan): bool
    {
        return $this->active($user)
            && $user->isSales()
            && $user->hasPermission('sales_daily_plans.submit_own')
            && (int) $salesDailyPlan->sales_user_id === (int) $user->id
            && $salesDailyPlan->status === 'draft'
            && $this->workspace->canViewBranch($user, (int) $salesDailyPlan->branch_id);
    }

    private function active(User $user): bool
    {
        return $user->isAccountActive() && $user->is_active;
    }
}
