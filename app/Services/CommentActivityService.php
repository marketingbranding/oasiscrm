<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CommentActivityService
{
    public function __construct(private readonly CommentableAccessService $access) {}

    public function log(string $event, Comment $comment, User $actor, Model $target, ?string $body = null): ActivityLog
    {
        return ActivityLog::create([
            'causer_id' => $actor->id,
            'subject_type' => Comment::class,
            'subject_id' => $comment->id,
            'event' => $event,
            'description' => "Aktivitas komentar #{$comment->id}: {$event}",
            'properties' => array_filter([
                'comment_id' => $comment->id,
                'parent_id' => $comment->parent_id,
                'target_id' => $target->getKey(),
                'target_alias' => $this->access->aliasFor($target),
                'branch_id' => $this->access->branchId($target),
                'project_id' => $this->access->projectId($target),
                'excerpt' => $body === null ? null : Str::limit($body, 100, ''),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    public function logMention(string $event, Comment $comment, User $actor, Model $target, int $mentionedUserId): ActivityLog
    {
        return ActivityLog::create([
            'causer_id' => $actor->id,
            'subject_type' => Comment::class,
            'subject_id' => $comment->id,
            'event' => $event,
            'description' => "Aktivitas komentar #{$comment->id}: {$event}",
            'properties' => array_filter([
                'comment_id' => $comment->id,
                'target_id' => $target->getKey(),
                'target_alias' => $this->access->aliasFor($target),
                'branch_id' => $this->access->branchId($target),
                'project_id' => $this->access->projectId($target),
                'mentioned_user_id' => $mentionedUserId,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }
}
