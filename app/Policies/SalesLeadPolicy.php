<?php

namespace App\Policies;

use App\Models\SalesLead;
use App\Models\User;
use App\Services\OrganizationScopeService;
use App\Services\WorkspaceAccessService;

class SalesLeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasScopedPermission('sales_pocketbook');
    }

    public function view(User $user, SalesLead $lead): bool
    {
        if (! $user->hasScopedPermission('sales_pocketbook')) {
            return false;
        }

        if ($user->hasPermission('sales_pocketbook.view_all')) {
            return true;
        }

        if ($user->isSales()) {
            return (int) $lead->sales_user_id === (int) $user->id
                && app(WorkspaceAccessService::class)->canViewBranch($user, $lead->branch_id);
        }

        $scope = app(OrganizationScopeService::class);

        return in_array((int) $lead->branch_id, $scope->branchIds($user, 'sales_pocketbook'), true)
            && in_array((int) $lead->project_id, $scope->projectIds($user, 'sales_pocketbook'), true)
            && app(WorkspaceAccessService::class)->canViewBranch($user, $lead->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission([
            'sales_pocketbook.manage_own',
            'sales_pocketbook.manage_all',
        ]);
    }

    public function update(User $user, SalesLead $lead): bool
    {
        if (! $user->hasScopedPermission('sales_pocketbook', 'manage') || ! $this->view($user, $lead)) {
            return false;
        }

        return $user->hasPermission('sales_pocketbook.manage_all')
            || ($user->isSales() && (int) $lead->sales_user_id === (int) $user->id)
            || app(WorkspaceAccessService::class)->canEditBranch($user, $lead->branch_id);
    }

    public function updateStage(User $user, SalesLead $lead): bool
    {
        return $this->update($user, $lead);
    }

    public function reverseStage(User $user, SalesLead $lead): bool
    {
        return ! $user->isSales() && $this->update($user, $lead);
    }
}
