<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;
use App\Services\CommentableAccessService;
use Illuminate\Database\Eloquent\Model;

class CommentPolicy
{
    public function __construct(private CommentableAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('comments.view');
    }

    public function view(User $user, Comment $comment): bool
    {
        return $user->hasPermission('comments.view') && $this->canAccessTarget($user, $comment);
    }

    public function create(User $user, Model $target): bool
    {
        return $user->hasPermission('comments.create') && $this->access->canView($user, $target);
    }

    public function reply(User $user, Comment $comment): bool
    {
        return $user->hasPermission('comments.reply') && $this->canAccessTarget($user, $comment);
    }

    public function update(User $user, Comment $comment): bool
    {
        return $user->hasPermission('comments.update_own')
            && (int) $comment->user_id === (int) $user->id
            && $this->canAccessTarget($user, $comment);
    }

    public function delete(User $user, Comment $comment): bool
    {
        return $user->hasPermission('comments.delete_own')
            && (int) $comment->user_id === (int) $user->id
            && $this->canAccessTarget($user, $comment);
    }

    public function restore(User $user, Comment $comment): bool
    {
        return $user->hasPermission('comments.moderate') && $this->canAccessTarget($user, $comment);
    }

    public function moderate(User $user, Comment $comment): bool
    {
        return $user->hasPermission('comments.moderate') && $this->canAccessTarget($user, $comment);
    }

    public function viewHistory(User $user, Comment $comment): bool
    {
        return $user->hasPermission('comments.view_history') && $this->canAccessTarget($user, $comment);
    }

    private function canAccessTarget(User $user, Comment $comment): bool
    {
        return $comment->commentable instanceof Model && $this->access->canView($user, $comment->commentable);
    }
}
