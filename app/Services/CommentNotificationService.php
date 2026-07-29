<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CommentNotificationService
{
    private const RELATED_TYPES = [
        'sales-lead' => 'sales_lead',
        'sales-agenda' => 'content_item',
        'planner-item' => 'content_item',
        'expense' => 'expense',
        'bridge-fund' => 'dana_talangan',
    ];

    public function __construct(private readonly CommentableAccessService $access) {}

    /** @param Collection<int, User> $mentions */
    public function created(Comment $comment, User $actor, Model $target, Collection $mentions, ?Comment $parent = null): void
    {
        $mentionedIds = [];
        foreach ($mentions as $recipient) {
            $mentionedIds[] = (int) $recipient->id;
            $this->create($recipient, $actor, $comment, $target, 'comment_mentioned');
        }

        if ($parent && $parent->user_id
            && (int) $parent->user_id !== (int) $actor->id
            && ! in_array((int) $parent->user_id, $mentionedIds, true)) {
            $owner = User::query()->find($parent->user_id);
            if ($owner) {
                $this->create($owner, $actor, $comment, $target, 'comment_replied');
            }
        }
    }

    /** @param Collection<int, User> $newMentions */
    public function updated(Comment $comment, User $actor, Model $target, Collection $newMentions): void
    {
        foreach ($newMentions as $recipient) {
            $this->create($recipient, $actor, $comment, $target, 'comment_mentioned');
        }
    }

    private function create(User $recipient, User $actor, Comment $comment, Model $target, string $type): void
    {
        if ((int) $recipient->id === (int) $actor->id
            || ! $recipient->is_active
            || ! $this->access->canView($recipient, $target)) {
            return;
        }

        $alias = $this->access->aliasFor($target);
        $relatedType = $alias ? self::RELATED_TYPES[$alias] ?? null : null;
        if (! $alias || ! $relatedType) {
            return;
        }

        $excerpt = $this->plainExcerpt($comment->body_plain ?? $comment->body);
        $label = $this->plain($this->access->label($target) ?: 'data');
        $actorName = $this->plain($actor->name);
        $notification = UserNotification::query()->firstOrCreate(
            [
                'user_id' => $recipient->id,
                'type' => $type,
                'comment_id' => $comment->id,
            ],
            [
                'actor_user_id' => $actor->id,
                'title' => $type === 'comment_replied' ? 'Balasan komentar baru' : 'Anda disebut dalam komentar',
                'message' => $type === 'comment_replied'
                    ? "{$actorName} membalas komentar Anda di {$label}: {$excerpt}"
                    : "{$actorName} menyebut Anda di {$label}: {$excerpt}",
                'related_type' => $relatedType,
                'related_id' => $target->getKey(),
                'data' => [
                    'actor_id' => (string) $actor->id,
                    'actor_name' => $actorName,
                    'comment_id' => (string) $comment->id,
                    'target_alias' => $alias,
                    'target_id' => (string) $target->getKey(),
                    'target_label' => $label,
                    'excerpt' => $excerpt,
                    'branch_id' => (string) ($this->access->branchId($target) ?? ''),
                    'project_id' => (string) ($this->access->projectId($target) ?? ''),
                    'created_at' => ($comment->created_at ?? now())->toIso8601String(),
                ],
            ],
        );

        if (! $notification->action_url) {
            $notification->forceFill(['action_url' => route('notifications.open', $notification)])->save();
        }
    }

    private function plainExcerpt(?string $value): string
    {
        return Str::limit($this->plain($value), 160, '');
    }

    private function plain(?string $value): string
    {
        return (string) Str::of(strip_tags((string) $value))->replaceMatches('/\s+/u', ' ')->trim();
    }
}
