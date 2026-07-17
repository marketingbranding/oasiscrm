<?php

namespace App\Policies;

use App\Models\ContentItem;
use App\Models\User;

class ContentItemPolicy
{
    public function view(User $user, ContentItem $item): bool
    {
        if ($user->canViewAllBranches()) {
            return true;
        }

        return $item->branch_id === $user->branch_id
            && ($item->visibility === 'team'
                || $item->created_by === $user->id
                || $item->assignees()->where('users.id', $user->id)->exists());
    }

    public function update(User $user, ContentItem $item): bool
    {
        return $this->view($user, $item);
    }

    public function delete(User $user, ContentItem $item): bool
    {
        return $this->view($user, $item);
    }
}
