<?php

namespace App\Services;

use App\Exceptions\CommentConflictException;
use App\Models\Comment;
use App\Models\CommentModeration;
use App\Models\CommentRevision;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CommentService
{
    public const DELETED_PLACEHOLDER = 'Komentar ini telah dihapus.';

    public function __construct(
        private readonly CommentActivityService $activity,
        private readonly CommentMentionService $mentionService,
        private readonly CommentNotificationService $notifications,
    ) {}

    public function create(User $actor, Model $target, string $body, ?int $parentId = null, array $mentionedUserIds = []): Comment
    {
        return DB::transaction(function () use ($actor, $target, $body, $parentId, $mentionedUserIds): Comment {
            $parent = null;
            if ($parentId !== null) {
                $parent = Comment::withTrashed()->lockForUpdate()->find($parentId);
                if (! $parent
                    || $parent->trashed()
                    || $parent->parent_id !== null
                    || $parent->commentable_type !== $target->getMorphClass()
                    || (int) $parent->commentable_id !== (int) $target->getKey()) {
                    throw $this->validationError('parent_id', 'Komentar induk tidak valid atau tidak dapat dibalas.');
                }

                $parent->setRelation('commentable', $target);
                Gate::forUser($actor)->authorize('reply', $parent);
            } else {
                Gate::forUser($actor)->authorize('create', [Comment::class, $target]);
            }

            $comment = Comment::create([
                'commentable_type' => $target->getMorphClass(),
                'commentable_id' => $target->getKey(),
                'user_id' => $actor->id,
                'parent_id' => $parent?->id,
                'body' => $body,
                'body_plain' => $body,
                'lock_version' => 0,
            ]);
            $comment->setRelation('commentable', $target);
            $mentions = $this->mentionService->validate($actor, $target, $mentionedUserIds);
            $comment->mentions()->sync($mentions->pluck('id')->all());
            $comment->setRelation('newMentionUsers', $mentions);
            $comment->setRelation('unchangedMentionUsers', collect());
            $comment->setRelation('removedMentionUsers', collect());
            foreach ($mentions as $mention) {
                $this->activity->logMention('mention_added', $comment, $actor, $target, (int) $mention->id);
            }
            $this->activity->log($parent ? 'reply_created' : 'comment_created', $comment, $actor, $target, $body);
            $this->notifications->created($comment, $actor, $target, $mentions, $parent);

            return $comment;
        });
    }

    public function update(Comment $comment, User $actor, string $body, int $expectedVersion, array $mentionedUserIds = []): Comment
    {
        return DB::transaction(function () use ($comment, $actor, $body, $expectedVersion, $mentionedUserIds): Comment {
            $current = Comment::withTrashed()->lockForUpdate()->findOrFail($comment->id);
            Gate::forUser($actor)->authorize('update', $current);
            $this->assertVersion($current, $expectedVersion);

            $previousIds = $current->mentionRecords()
                ->whereNotNull('mentioned_user_id')->pluck('mentioned_user_id')->map(fn ($id) => (int) $id)->all();
            $mentions = $this->mentionService->validate($actor, $current->commentable, $mentionedUserIds);
            $nextIds = $mentions->pluck('id')->map(fn ($id) => (int) $id)->all();
            CommentRevision::create([
                'comment_id' => $current->id,
                'edited_by' => $actor->id,
                'previous_body' => $current->body,
                'previous_mentioned_user_ids' => $previousIds,
            ]);

            $current->forceFill([
                'body' => $body,
                'body_plain' => $body,
                'edited_at' => now(),
                'lock_version' => $current->lock_version + 1,
            ])->save();
            $target = $current->commentable;
            $current->mentions()->sync($nextIds);
            $newIds = array_values(array_diff($nextIds, $previousIds));
            $unchangedIds = array_values(array_intersect($nextIds, $previousIds));
            $removedIds = array_values(array_diff($previousIds, $nextIds));
            $current->setRelation('newMentionUsers', $mentions->whereIn('id', $newIds)->values());
            $current->setRelation('unchangedMentionUsers', $mentions->whereIn('id', $unchangedIds)->values());
            $removedUsers = $removedIds === []
                ? collect()
                : User::query()->whereIntegerInRaw('id', $removedIds)->get();
            $current->setRelation('removedMentionUsers', $removedUsers);
            foreach ($newIds as $userId) {
                $this->activity->logMention('mention_added', $current, $actor, $target, $userId);
            }
            foreach ($removedIds as $userId) {
                $this->activity->logMention('mention_removed', $current, $actor, $target, $userId);
            }
            $this->activity->log('comment_edited', $current, $actor, $target, $body);
            $this->notifications->updated($current, $actor, $target, $current->getRelation('newMentionUsers'));

            return $current;
        });
    }

    public function delete(Comment $comment, User $actor, int $expectedVersion): Comment
    {
        return DB::transaction(function () use ($comment, $actor, $expectedVersion): Comment {
            $current = Comment::withTrashed()->lockForUpdate()->findOrFail($comment->id);
            Gate::forUser($actor)->authorize('delete', $current);
            $this->assertVersion($current, $expectedVersion);
            $target = $current->commentable;
            $current->forceFill(['lock_version' => $current->lock_version + 1])->save();
            $current->delete();
            $this->activity->log('comment_deleted', $current, $actor, $target);

            return $current;
        });
    }

    public function restore(Comment $comment, User $actor): Comment
    {
        return DB::transaction(function () use ($comment, $actor): Comment {
            $current = Comment::withTrashed()->lockForUpdate()->findOrFail($comment->id);
            Gate::forUser($actor)->authorize('restore', $current);
            if (! $current->trashed()) {
                throw $this->validationError('comment', 'Komentar ini tidak sedang dihapus.');
            }

            $target = $current->commentable;
            $current->restore();
            $current->forceFill(['lock_version' => $current->lock_version + 1])->save();
            $this->activity->log('comment_restored', $current, $actor, $target);

            return $current;
        });
    }

    public function moderate(Comment $comment, User $actor, string $reason): Comment
    {
        return DB::transaction(function () use ($comment, $actor, $reason): Comment {
            $current = Comment::withTrashed()->lockForUpdate()->findOrFail($comment->id);
            Gate::forUser($actor)->authorize('moderate', $current);
            if ($current->trashed()) {
                throw $this->validationError('comment', 'Komentar ini sudah dihapus.');
            }

            CommentModeration::create([
                'comment_id' => $current->id,
                'moderated_by' => $actor->id,
                'action' => 'hide',
                'reason' => $reason,
            ]);
            $target = $current->commentable;
            $current->forceFill(['lock_version' => $current->lock_version + 1])->save();
            $current->delete();
            $this->activity->log('comment_moderated', $current, $actor, $target);

            return $current;
        });
    }

    public function paginate(Model $target, int $page = 1): LengthAwarePaginator
    {
        return Comment::query()
            ->withTrashed()
            ->where('commentable_type', $target->getMorphClass())
            ->where('commentable_id', $target->getKey())
            ->whereNull('parent_id')
            ->where(fn ($query) => $query->whereNull('deleted_at')
                ->orWhereHas('replies', fn ($replies) => $replies->withTrashed()))
            ->with([
                'user:id,name',
                'mentions:id,name',
                'replies' => fn ($query) => $query->withTrashed()->oldest('created_at')->oldest('id')
                    ->with(['user:id,name', 'mentions:id,name']),
            ])
            ->withCount(['replies' => fn ($query) => $query->withTrashed()])
            ->latest('created_at')
            ->latest('id')
            ->paginate(20, ['*'], 'page', $page);
    }

    public function serialize(Comment $comment, User $viewer, ?Model $target = null): array
    {
        if ($target) {
            $comment->setRelation('commentable', $target);
        }
        $deleted = $comment->trashed();
        $replies = $comment->relationLoaded('replies')
            ? $comment->replies->map(fn (Comment $reply) => $this->serialize($reply, $viewer, $target))->values()->all()
            : [];

        return [
            'id' => $comment->id,
            'parent_id' => $comment->parent_id,
            'body' => $deleted ? self::DELETED_PLACEHOLDER : $comment->body_plain,
            'is_deleted' => $deleted,
            'is_edited' => ! $deleted && $comment->edited_at !== null,
            'edited_at' => $deleted ? null : $comment->edited_at?->toISOString(),
            'lock_version' => $comment->lock_version,
            'created_at' => $comment->created_at?->toISOString(),
            'updated_at' => $comment->updated_at?->toISOString(),
            'user' => $comment->user ? ['id' => $comment->user->id, 'name' => $comment->user->name] : null,
            'mentions' => $deleted ? [] : $comment->mentions->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values()->all(),
            'reply_count' => $comment->replies_count ?? count($replies),
            'replies' => $replies,
            'can_reply' => Gate::forUser($viewer)->allows('reply', $comment),
            'can_update' => Gate::forUser($viewer)->allows('update', $comment),
            'can_delete' => Gate::forUser($viewer)->allows('delete', $comment),
            'can_restore' => Gate::forUser($viewer)->allows('restore', $comment),
            'can_moderate' => Gate::forUser($viewer)->allows('moderate', $comment),
            'can_view_history' => Gate::forUser($viewer)->allows('viewHistory', $comment),
        ];
    }

    public function history(Comment $comment): array
    {
        return $comment->revisions()->with('editor:id,name')->get()->map(fn (CommentRevision $revision) => [
            'id' => $revision->id,
            'previous_body' => $revision->previous_body,
            'previous_mentioned_user_ids' => $revision->previous_mentioned_user_ids ?? [],
            'edited_by' => $revision->editor ? ['id' => $revision->editor->id, 'name' => $revision->editor->name] : null,
            'created_at' => $revision->created_at?->toISOString(),
        ])->all();
    }

    private function assertVersion(Comment $comment, int $expectedVersion): void
    {
        if ($comment->lock_version !== $expectedVersion) {
            throw new CommentConflictException($comment);
        }
    }

    private function validationError(string $field, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422));
    }
}
