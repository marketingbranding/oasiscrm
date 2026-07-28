<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\ModerateCommentRequest;
use App\Models\Comment;
use App\Services\CommentService;
use Illuminate\Http\JsonResponse;

class CommentModerationController extends Controller
{
    public function __construct(private readonly CommentService $comments) {}

    public function store(ModerateCommentRequest $request, Comment $comment): JsonResponse
    {
        $comment = $this->comments->moderate($comment, $request->user(), $request->validated('reason'));
        $comment->load(['user:id,name', 'mentions:id,name']);

        return response()->json(['data' => $this->comments->serialize($comment, $request->user())]);
    }
}
