<?php

namespace App\Policies;

use App\Models\ContentItem;
use App\Models\User;
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

        if ($user->hasPermission('work_planner.view_all')) {
            return true;
        }

        return app(WorkspaceAccessService::class)->canViewBranch($user, $item->branch_id)
            && ($item->visibility === 'team'
                || $item->created_by === $user->id
                || $item->assignees()->where('users.id', $user->id)->exists());
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

        return $this->view($user, $item)
            && app(WorkspaceAccessService::class)->canEditBranch($user, $item->branch_id);
    }

    public function delete(User $user, ContentItem $item): bool
    {
        return $this->update($user, $item);
    }
}
