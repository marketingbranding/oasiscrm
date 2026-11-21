<?php

namespace App\Policies;

use App\Models\SalesLead;
use App\Models\User;
use App\Services\AppSettingResolver;
use App\Services\CoordinatorLeadTeamService;
use App\Services\CrmAuthorizationService;
use App\Services\OrganizationScopeService;
use App\Services\SalesTeamScopeService;
use App\Services\WorkspaceAccessService;
use App\Support\FeatureFlag;

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
        if (! $this->authorization->hasAnyScope($user, 'sales_pocketbook')) {
            return false;
        }

        if ($user->hasPrimaryRole('supervisor')) {
            $organizationScope = app(OrganizationScopeService::class);
            $workspaceAccess = app(WorkspaceAccessService::class);
            $branchIds = array_values(array_intersect(
                $organizationScope->branchIds($user, 'sales_pocketbook'),
                $workspaceAccess->accessibleBranchIds($user),
            ));
            $projectIds = array_values(array_intersect(
                $organizationScope->projectIds($user, 'sales_pocketbook'),
                $workspaceAccess->accessibleProjectIds($user),
            ));
            $team = app(SalesTeamScopeService::class)->forWorkspace($user, $branchIds, $projectIds);

            return $team['sales']->contains('id', $lead->sales_user_id)
                && in_array((int) $lead->branch_id, $branchIds, true)
                && in_array((int) $lead->project_id, $projectIds, true)
                && $workspaceAccess->canViewBranch($user, $lead->branch_id);
        }

        if ($user->hasPermission('sales_pocketbook.view_all')
            && ! app(WorkspaceAccessService::class)->isBranchScopedOperator($user)) {
            return true;
        }

        return $this->authorization->canViewSalesLead($user, $lead);
    }

    public function create(User $user): bool
    {
        if ($user->isAdminCabang() || $user->hasPrimaryRole(['branch_manager', 'supervisor'])) {
            return false;
        }

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
        if (FeatureFlag::enabled('lead_stages_v3') && $user->isSales() && in_array($lead->resolveStageV3(), ['utj', 'gugur'], true)) {
            return false;
        }

        if (FeatureFlag::enabled('org_hierarchical_monitoring')
            && $this->isReadOnlySalesMonitor($user)
            && ! $this->canEditHistoricalOwnedLead($user, $lead)) {
            return false;
        }

        if (! $this->authorization->canViewSalesLead($user, $lead)) {
            return false;
        }

        if ($user->isSales()) {
            $workspaceAccess = app(WorkspaceAccessService::class);

            return (int) $lead->sales_user_id === $user->id
                && $this->authorization->can($user, 'sales_pocketbook.manage_own')
                && $workspaceAccess->canEditBranch($user, (int) $lead->branch_id)
                && $workspaceAccess->canAccessProject($user, (int) $lead->project_id);
        }

        if ($user->hasPrimaryRole('supervisor')) {
            return false;
        }

        if (app(WorkspaceAccessService::class)->isBranchScopedOperator($user)) {
            return $this->canEditHistoricalOwnedLead($user, $lead);
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

    public function updateStageV3(User $user, SalesLead $lead): bool
    {
        if (! FeatureFlag::enabled('lead_stages_v3') || $this->canEditHistoricalOwnedLead($user, $lead) || ! $this->update($user, $lead)) {
            return false;
        }

        if (! $user->isSales()) {
            return true;
        }

        $stage = $lead->resolveStageV3();
        if ($stage === 'gugur' || $stage === 'utj') {
            return false;
        }

        $allowed = SalesLead::STAGE_V3_ORDER;
        $limit = app(AppSettingResolver::class)->string('sales_lead.sales_max_edit_stage');

        return array_search($stage, $allowed, true) <= array_search($limit, $allowed, true);
    }

    public function reverseStage(User $user, SalesLead $lead): bool
    {
        return ! $user->isSales()
            && ! $this->canEditHistoricalOwnedLead($user, $lead)
            && $this->update($user, $lead);
    }

    public function updateLifecycleStatus(User $user, SalesLead $lead): bool
    {
        if (FeatureFlag::enabled('lead_stages_v3')) {
            if ($user->isSales()) {
                return $this->updateStageV3($user, $lead);
            }
        }

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
        if (FeatureFlag::enabled('lead_stages_v3')
            && ($lead->lead_stage === 'gugur' || ($user->isSales() && $lead->lead_stage === 'utj'))) {
            return false;
        }
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
        if (FeatureFlag::enabled('lead_stages_v3')
            && ($lead->lead_stage === 'gugur' || ($user->isSales() && $lead->lead_stage === 'utj'))) {
            return false;
        }
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
        if (FeatureFlag::enabled('lead_stages_v3') && $lead->lead_stage === 'gugur') {
            return false;
        }
        if ($this->canEditHistoricalOwnedLead($user, $lead)) {
            return false;
        }

        if ($lead->resolveStageV3() !== 'utj') {
            if (! $user->isAdminCabang() && ! $user->hasPrimaryRole(['sas', 'sales_coordinator'])) {
                return false;
            }

            return $this->authorization->canViewSalesLead($user, $lead);
        }

        return $this->update($user, $lead);
    }

    public function submitToSlik(User $user, SalesLead $lead): bool
    {
        return (! FeatureFlag::enabled('lead_stages_v3') || $lead->lead_stage !== 'gugur')
            && ! $this->canEditHistoricalOwnedLead($user, $lead)
            && $this->update($user, $lead);
    }

    public function markSlikRejected(User $user, SalesLead $lead): bool
    {
        return (! FeatureFlag::enabled('lead_stages_v3') || $lead->lead_stage !== 'gugur')
            && ! $this->canEditHistoricalOwnedLead($user, $lead)
            && $this->update($user, $lead);
    }

    public function convertToFreelance(User $user, SalesLead $lead): bool
    {
        return (! FeatureFlag::enabled('lead_stages_v3') || $lead->lead_stage !== 'gugur')
            && ! $this->canEditHistoricalOwnedLead($user, $lead)
            && $this->update($user, $lead);
    }

    public function canEditHistoricalOwnedLead(User $user, SalesLead $lead): bool
    {
        return ($user->isAdminCabang() || $user->hasPrimaryRole('branch_manager'))
            && app(WorkspaceAccessService::class)->canEditBranch($user, $lead->branch_id)
            && $lead->sales !== null
            && app(WorkspaceAccessService::class)->canViewHistoricalBranch($lead->sales, $lead->branch_id);
    }

    private function isReadOnlySalesMonitor(User $user): bool
    {
        return $user->hasPrimaryRole(['supervisor', 'manager', 'ass_manager', 'branch_manager'])
            || $user->isAdminCabang();
    }
}
