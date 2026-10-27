<?php

namespace App\Policies;

use App\Models\SalesLead;
use App\Models\User;
use App\Services\CoordinatorLeadTeamService;
use App\Services\CrmAuthorizationService;
use App\Services\WorkspaceAccessService;

class SalesLeadPolicy
{
    public function __construct(
        private readonly CrmAuthorizationService $authorization,
        private readonly CoordinatorLeadTeamService $coordinatorTeam,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->hasAnyScope($user, 'sales_pocketbook');
    }

    public function view(User $user, SalesLead $lead): bool
    {
        return $this->authorization->canViewSalesLead($user, $lead);
    }

    public function create(User $user): bool
    {
        if (! $this->authorization->can($user, 'sales_pocketbook.manage_own')
            && ! $this->authorization->can($user, 'sales_pocketbook.manage_all')) {
            return $this->authorization->can($user, 'sales_pocketbook.manage_team')
                && $user->hasPrimaryRole('sales_coordinator');
        }

        if ($user->hasPrimaryRole('sales_coordinator')) {
            return $this->authorization->can($user, 'sales_pocketbook.manage_team');
        }

        return ! $user->hasPrimaryRole('supervisor');
    }

    public function update(User $user, SalesLead $lead): bool
    {
        if (! $this->authorization->canViewSalesLead($user, $lead)) {
            return false;
        }

        if ($user->isSales() || $user->hasPrimaryRole('supervisor')) {
            return false;
        }

        if ($this->canEditHistoricalOwnedLead($user, $lead)) {
            return true;
        }

        if ($user->hasPrimaryRole('sales_coordinator')) {
            return $this->authorization->can($user, 'sales_pocketbook.manage_team')
                && $this->coordinatorTeam->contains($user, (int) $lead->sales_user_id);
        }

        return $this->authorization->can($user, 'sales_pocketbook.manage_all')
            || ($this->authorization->hasAnyScope($user, 'sales_pocketbook', 'manage')
                && $this->authorization->canEditBranch($user, (int) $lead->branch_id));
    }

    public function delete(User $user, SalesLead $lead): bool
    {
        return $user->isSuperadmin();
    }

    public function updateStage(User $user, SalesLead $lead): bool
    {
        return ! $this->canEditHistoricalOwnedLead($user, $lead)
            && $this->update($user, $lead);
    }

    public function reverseStage(User $user, SalesLead $lead): bool
    {
        return ! $user->isSales()
            && ! $this->canEditHistoricalOwnedLead($user, $lead)
            && $this->update($user, $lead);
    }

    public function updateLifecycleStatus(User $user, SalesLead $lead): bool
    {
        return ! $this->canEditHistoricalOwnedLead($user, $lead)
            && $this->update($user, $lead);
    }

    public function viewSiteVisit(User $user, SalesLead $lead): bool
    {
        return $this->view($user, $lead)
            && (! $user->hasPrimaryRole('sales_coordinator')
                || $this->coordinatorTeam->contains($user, (int) $lead->sales_user_id));
    }

    public function recordSiteVisit(User $user, SalesLead $lead): bool
    {
        if ($this->canEditHistoricalOwnedLead($user, $lead)) {
            return false;
        }

        if ($user->isSales()) {
            return $this->authorization->can($user, 'sales_pocketbook.manage_own')
                && (int) $lead->sales_user_id === (int) $user->id
                && $this->view($user, $lead);
        }

        if ($user->hasPrimaryRole('sales_coordinator')) {
            return $this->authorization->can($user, 'sales_pocketbook.manage_team')
                && $this->coordinatorTeam->contains($user, (int) $lead->sales_user_id)
                && $this->view($user, $lead);
        }

        return $this->update($user, $lead);
    }

    public function markUtjDirect(User $user, SalesLead $lead): bool
    {
        if ($this->canEditHistoricalOwnedLead($user, $lead)) {
            return false;
        }

        if ($user->isSales()) {
            return $this->authorization->can($user, 'sales_pocketbook.manage_own')
                && (int) $lead->sales_user_id === (int) $user->id
                && $this->view($user, $lead);
        }

        if ($user->hasPrimaryRole('sales_coordinator')) {
            return $this->authorization->can($user, 'sales_pocketbook.manage_team')
                && $this->coordinatorTeam->contains($user, (int) $lead->sales_user_id)
                && $this->view($user, $lead);
        }

        return $this->update($user, $lead);
    }

    public function convertToConsumer(User $user, SalesLead $lead): bool
    {
        return ! $this->canEditHistoricalOwnedLead($user, $lead)
            && $this->update($user, $lead);
    }

    public function submitToSlik(User $user, SalesLead $lead): bool
    {
        return ! $this->canEditHistoricalOwnedLead($user, $lead)
            && $this->update($user, $lead);
    }

    public function markSlikRejected(User $user, SalesLead $lead): bool
    {
        return ! $this->canEditHistoricalOwnedLead($user, $lead)
            && $this->update($user, $lead);
    }

    public function convertToFreelance(User $user, SalesLead $lead): bool
    {
        return ! $this->canEditHistoricalOwnedLead($user, $lead)
            && $this->update($user, $lead);
    }

    private function canEditHistoricalOwnedLead(User $user, SalesLead $lead): bool
    {
        return $user->hasPrimaryRole(['admin', 'branch_manager'])
            && app(WorkspaceAccessService::class)->canEditBranch($user, $lead->branch_id)
            && $lead->sales !== null
            && app(WorkspaceAccessService::class)->canViewHistoricalBranch($lead->sales, $lead->branch_id);
    }
}
