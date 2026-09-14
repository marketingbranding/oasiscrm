<?php

namespace App\Policies;

use App\Models\ContentItem;
use App\Models\User;
use App\Services\OrganizationScopeService;
use App\Services\ProjectIdentityResolver;
use App\Services\WorkspaceAccessService;

class ContentItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasScopedPermission('work_planner');
    }

    public function view(User $user, ContentItem $item): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return $this->withinScope($user, $item, 'view');
    }

    public function update(User $user, ContentItem $item): bool
    {
        if (! $user->hasPermission('work_planner.update')) {
            return false;
        }

        if ($user->isSales()) {
            return app(WorkspaceAccessService::class)->canEditBranch($user, $item->branch_id)
                && ($item->created_by === $user->id
                    || $item->assignees()->where('users.id', $user->id)->exists());
        }

        return ($user->hasPermission('work_planner.manage_all') || $this->withinScope($user, $item, 'view'))
            && app(WorkspaceAccessService::class)->canManageBranch($user, $item->branch_id, 'work_planner');
    }

    public function delete(User $user, ContentItem $item): bool
    {
        return $this->update($user, $item);
    }

    private function withinScope(User $user, ContentItem $item, string $action): bool
    {
        if ($user->hasPermission("work_planner.{$action}_all")) {
            return true;
        }

        $scope = app(OrganizationScopeService::class);
        $related = $item->visibility === 'team'
            || $item->created_by === $user->id
            || $item->assignees()->where('users.id', $user->id)->exists();
        if (! $related || ! in_array((int) $item->branch_id, $scope->branchIds($user, 'work_planner', $action), true)) {
            return false;
        }
        if (! $scope->requiresProjectScope($user, 'work_planner', $action)) {
            return true;
        }

        if (! $item->sales_project_id) {
            if ($item->created_by === $user->id
                || $item->assignees()->where('users.id', $user->id)->exists()
                || in_array((int) $item->created_by, $scope->hierarchyIds($user), true)) {
                return true;
            }

            return ! $this->freeTextProjectBlocked($user, $item);
        }

        return in_array((int) $item->sales_project_id, $scope->projectIds($user, 'work_planner', $action), true)
            || $item->created_by === $user->id
            || $item->assignees()->where('users.id', $user->id)->exists()
            || in_array((int) $item->created_by, $scope->hierarchyIds($user), true);
    }

    private function freeTextProjectBlocked(User $user, ContentItem $item): bool
    {
        if (blank($item->project_name)) {
            return false;
        }

        $project = app(ProjectIdentityResolver::class)->resolveExactOrNull((int) $item->branch_id, $item->project_name);

        return $project !== null
            && ! in_array($project->id, app(OrganizationScopeService::class)->projectIds($user, 'work_planner', 'view'), true);
    }
}
