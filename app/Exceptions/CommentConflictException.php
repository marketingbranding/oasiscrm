<?php

namespace App\Exceptions;

use App\Models\Comment;
use App\Services\CommentableAccessService;
use App\Services\OptimisticLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CommentConflictException extends RuntimeException
{
    public function __construct(public readonly Comment $comment)
    {
        parent::__construct(OptimisticLockService::MESSAGE);
    }

    public function render(Request $request): JsonResponse
    {
        $comment = $this->comment;

        return response()->json([
            'ok' => false,
            'code' => 'record_modified',
            'message' => OptimisticLockService::MESSAGE,
            'record_type' => 'comment',
            'record_id' => $comment->id,
            'current_updated_at' => $comment->updated_at?->toISOString(),
            'current_lock_version' => $comment->lock_version,
            'reload_url' => $comment->commentable
                ? app(CommentableAccessService::class)->targetUrl($comment->commentable, 'comment-'.$comment->id)
                : null,
        ], 409);
    }
}
