<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CrmAuthorizationService
{
    public function __construct(
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly OrganizationScopeService $organizationScope,
        private readonly SalesTeamScopeService $salesTeamScope,
    ) {}

    public function can(User $actor, string $permission): bool
    {
        return $this->isActive($actor) && $actor->hasPermission($permission);
    }

    public function hasScope(User $actor, string $module, string $action, string $scope): bool
    {
        return $this->isActive($actor) && $this->organizationScope->hasScope($actor, $module, $action, $scope);
    }

    public function hasAnyScope(User $actor, string $module, string $action = 'view'): bool
    {
        return collect(['own', 'team', 'assigned', 'project', 'branch', 'all'])
            ->contains(fn (string $scope) => $this->hasScope($actor, $module, $action, $scope));
    }

    /** @return array<int> */
    public function visibleUserIds(User $actor, string $module = 'sales_pocketbook', string $action = 'view'): array
    {
        return $this->isActive($actor)
            ? $this->organizationScope->visibleUserIds($actor, $module, $action)
            : [];
    }

    /** @return array<int> */
    public function visibleBranchIds(User $actor, string $module = 'sales_pocketbook', string $action = 'view'): array
    {
        return $this->isActive($actor)
            ? array_values(array_intersect(
                $this->organizationScope->branchIds($actor, $module, $action),
                $this->workspaceAccess->accessibleBranchIds($actor),
            ))
            : [];
    }

    /** @return array<int> */
    public function visibleProjectIds(User $actor, string $module = 'sales_pocketbook', string $action = 'view'): array
    {
        return $this->isActive($actor)
            ? array_values(array_intersect(
                $this->organizationScope->projectIds($actor, $module, $action),
                $this->workspaceAccess->accessibleProjectIds($actor),
            ))
            : [];
    }

    public function canEditBranch(User $actor, int $branchId, string $module = 'sales_pocketbook'): bool
    {
        return $this->isActive($actor) && $this->workspaceAccess->canEditBranch($actor, $branchId, $module);
    }

    public function allowsRecord(
        User $actor,
        string $module,
        string $action,
        int $branchId,
        ?int $projectId = null,
        ?int $ownerId = null,
    ): bool {
        if (! $this->isActive($actor) || ! $this->workspaceAccess->canViewBranch($actor, $branchId)) {
            return false;
        }

        if ($this->hasScope($actor, $module, $action, 'all')) {
            return true;
        }

        $branchIds = $this->visibleBranchIds($actor, $module, $action);
        if (! in_array($branchId, $branchIds, true)) {
            return false;
        }

        if ($this->hasScope($actor, $module, $action, 'branch')
            && ! $this->organizationScope->requiresProjectScope($actor, $module, $action)) {
            return true;
        }

        if ($ownerId !== null && $this->hasScope($actor, $module, $action, 'own') && (int) $ownerId === (int) $actor->id) {
            return $projectId === null || in_array($projectId, $this->visibleProjectIds($actor, $module, $action), true);
        }

        if ($ownerId !== null && $this->hasScope($actor, $module, $action, 'team')
            && in_array((int) $ownerId, $this->visibleUserIds($actor, $module, $action), true)) {
            return $projectId === null || in_array($projectId, $this->visibleProjectIds($actor, $module, $action), true);
        }

        return $projectId !== null
            && $this->hasScope($actor, $module, $action, 'assigned')
            && in_array($projectId, $this->visibleProjectIds($actor, $module, $action), true)
            && ($ownerId === null || in_array((int) $ownerId, $this->visibleUserIds($actor, $module, $action), true));
    }

    public function canViewSalesLead(User $actor, SalesLead $lead): bool
    {
        if (! $this->isActive($actor)) {
            return false;
        }

        if ($actor->isSales()) {
            return $this->can($actor, 'sales_pocketbook.view_own')
                && (int) $lead->sales_user_id === (int) $actor->id
                && ($this->allowsRecord($actor, 'sales_pocketbook', 'view', (int) $lead->branch_id, (int) $lead->project_id, (int) $lead->sales_user_id)
                    || $this->workspaceAccess->canViewHistoricalBranch($actor, (int) $lead->branch_id));
        }

        if ($actor->hasPrimaryRole('sales_coordinator')) {
            return $this->can($actor, 'sales_pocketbook.view_team')
                && $this->salesTeamScope->contains($actor, (int) $lead->sales_user_id)
                && $this->allowsRecord($actor, 'sales_pocketbook', 'view', (int) $lead->branch_id, (int) $lead->project_id, (int) $lead->sales_user_id);
        }

        return $this->allowsRecord($actor, 'sales_pocketbook', 'view', (int) $lead->branch_id, (int) $lead->project_id, (int) $lead->sales_user_id);
    }

    public function canViewSalesAgenda(User $actor, ContentItem $agenda): bool
    {
        if (! $this->isActive($actor)) {
            return false;
        }

        if ($actor->isSales()) {
            return $this->can($actor, 'sales_pocketbook.view_own')
                && (int) $agenda->owner_user_id === (int) $actor->id
                && $this->allowsRecord($actor, 'sales_pocketbook', 'view', (int) $agenda->branch_id, $this->agendaProjectId($agenda), (int) $agenda->owner_user_id);
        }

        if ($actor->hasPrimaryRole('sales_coordinator')) {
            return $this->can($actor, 'sales_pocketbook.view_team')
                && $this->salesTeamScope->contains($actor, (int) $agenda->owner_user_id)
                && $this->allowsRecord($actor, 'sales_pocketbook', 'view', (int) $agenda->branch_id, $this->agendaProjectId($agenda), (int) $agenda->owner_user_id);
        }

        return $this->allowsRecord($actor, 'sales_pocketbook', 'view', (int) $agenda->branch_id, $this->agendaProjectId($agenda), (int) $agenda->owner_user_id);
    }

    public function canCreateSalesAgenda(User $actor): bool
    {
        return $this->can($actor, 'sales_pocketbook.manage_own') && $actor->isSales();
    }

    public function canUpdateSalesAgenda(User $actor, ContentItem $agenda): bool
    {
        if ($agenda->daily_plan_id && $agenda->dailyPlan?->status === 'submitted') {
            return $this->can($actor, 'sales_pocketbook.view_own')
                && $actor->isSales()
                && (int) $agenda->owner_user_id === (int) $actor->id
                && $this->canViewSalesAgenda($actor, $agenda);
        }

        return $this->can($actor, 'sales_pocketbook.manage_own')
            && $actor->isSales()
            && (int) $agenda->owner_user_id === (int) $actor->id
            && $this->canViewSalesAgenda($actor, $agenda);
    }

    public function salesLeadQuery(Builder $query, User $actor, string $action = 'view'): Builder
    {
        if (! $this->isActive($actor)) {
            return $query->whereKey(0);
        }

        if ($this->hasScope($actor, 'sales_pocketbook', $action, 'all')
            && ! $this->workspaceAccess->isBranchScopedOperator($actor)) {
            return $query;
        }

        if ($actor->isSales() && $action === 'view') {
            $historicalBranchIds = $this->workspaceAccess->historicalBranchIds($actor);

            return $query
                ->where('sales_user_id', $actor->id)
                ->where(function (Builder $scoped) use ($actor, $historicalBranchIds): void {
                    $scoped->whereIn('branch_id', $historicalBranchIds)
                        ->orWhere(function (Builder $current) use ($actor): void {
                            $current
                                ->whereIn('sales_user_id', $this->visibleUserIds($actor, 'sales_pocketbook', 'view'))
                                ->whereIn('branch_id', $this->visibleBranchIds($actor, 'sales_pocketbook', 'view'))
                                ->when(
                                    $this->organizationScope->requiresProjectScope($actor, 'sales_pocketbook', 'view'),
                                    fn (Builder $scopedQuery): Builder => $scopedQuery->whereIn('project_id', $this->visibleProjectIds($actor, 'sales_pocketbook', 'view')),
                                );
                        });
                });
        }

        return $query
            ->whereIn('sales_user_id', $this->visibleUserIds($actor, 'sales_pocketbook', $action))
            ->whereIn('branch_id', $this->visibleBranchIds($actor, 'sales_pocketbook', $action))
            ->when(
                $this->organizationScope->requiresProjectScope($actor, 'sales_pocketbook', $action),
                fn (Builder $scoped) => $scoped->whereIn('project_id', $this->visibleProjectIds($actor, 'sales_pocketbook', $action)),
            );
    }

    private function isActive(User $actor): bool
    {
        return $actor->isAccountActive() && $actor->is_active;
    }

    private function agendaProjectId(ContentItem $agenda): ?int
    {
        return $agenda->sales_project_id ? (int) $agenda->sales_project_id : null;
    }
}
