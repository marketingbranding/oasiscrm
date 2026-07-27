<?php

namespace App\Policies;

use App\Models\SalesLead;
use App\Models\User;
use App\Services\WorkspaceAccessService;

class SalesLeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasModuleRole($user);
    }

    public function view(User $user, SalesLead $lead): bool
    {
        if (! $this->hasModuleRole($user)) {
            return false;
        }

        if ($user->canViewAllBranches()) {
            return true;
        }

        if ($user->isSales()) {
            return (int) $lead->sales_user_id === (int) $user->id
                && app(WorkspaceAccessService::class)->canViewBranch($user, $lead->branch_id);
        }

        return app(WorkspaceAccessService::class)->canViewBranch($user, $lead->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin() || $user->hasPrimaryRole(['sales', 'pusat']);
    }

    public function update(User $user, SalesLead $lead): bool
    {
        if (! $this->view($user, $lead)) {
            return false;
        }

        return $user->canViewAllBranches()
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

    private function hasModuleRole(User $user): bool
    {
        return $user->isSuperadmin() || $user->hasPrimaryRole(['sales', 'manager', 'admin', 'pusat']);
    }
}
