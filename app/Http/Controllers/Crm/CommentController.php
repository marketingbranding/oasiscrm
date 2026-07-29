<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\DeleteCommentRequest;
use App\Http\Requests\Crm\StoreCommentRequest;
use App\Http\Requests\Crm\UpdateCommentRequest;
use App\Models\Comment;
use App\Services\CommentableAccessService;
use App\Services\CommentMentionService;
use App\Services\CommentService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CommentController extends Controller
{
    public function __construct(
        private readonly CommentService $comments,
        private readonly CommentableAccessService $access,
        private readonly CommentMentionService $mentions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'alias' => ['required', 'string', Rule::in(['sales-lead', 'planner-item', 'sales-agenda', 'expense', 'bridge-fund'])],
            'id' => ['required', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $target = $this->access->resolve($data['alias'], $data['id']);
        abort_unless($target, 404);
        $this->authorize('viewAny', Comment::class);
        abort_unless($this->access->canView($request->user(), $target), 403);

        $paginator = $this->comments->paginate($target, $request->user(), (int) ($data['page'] ?? 1));
        $items = $paginator->getCollection()->reverse()->values()
            ->map(fn (Comment $comment) => $this->comments->serialize($comment, $request->user(), $target));

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $this->comments->count($target),
                'top_level_total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'can_create' => Gate::forUser($request->user())->allows('create', [Comment::class, $target]),
                'can_mention' => $request->user()->hasPermission('comments.mention'),
                'target' => ['alias' => $data['alias'], 'id' => (int) $data['id']],
            ],
        ]);
    }

    public function mentionableUsers(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'alias' => ['required', 'string', Rule::in(['sales-lead', 'planner-item', 'sales-agenda', 'expense', 'bridge-fund'])],
            'id' => ['required', 'integer', 'min:1'],
            'query' => ['nullable', 'string', 'max:100'],
        ]);
        if ($validator->fails()) {
            throw new HttpResponseException(response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
            ], 422));
        }
        $data = $validator->validated();
        $target = $this->access->resolve($data['alias'], $data['id']);
        abort_unless($target, 404);
        $this->authorize('viewAny', Comment::class);
        abort_unless($request->user()->hasPermission('comments.mention'), 403);
        abort_unless($this->access->canView($request->user(), $target), 403);

        return response()->json(['data' => $this->mentions->search($request->user(), $target, $data['query'] ?? null)]);
    }

    public function store(StoreCommentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $target = $this->access->resolve($data['alias'], $data['id']);
        abort_unless($target, 404);
        abort_unless($this->access->canView($request->user(), $target), 403);

        if (isset($data['parent_id'])) {
            $parent = Comment::withTrashed()->find($data['parent_id']);
            if (! $parent
                || $parent->trashed()
                || $parent->parent_id !== null
                || $parent->commentable_type !== $target->getMorphClass()
                || (int) $parent->commentable_id !== (int) $target->getKey()) {
                $message = 'Komentar induk tidak valid atau tidak dapat dibalas.';
                throw new HttpResponseException(response()->json([
                    'message' => $message,
                    'errors' => ['parent_id' => [$message]],
                ], 422));
            }
            $parent->setRelation('commentable', $target);
            $this->authorize('reply', $parent);
        } else {
            $this->authorize('create', [Comment::class, $target]);
        }

        $comment = $this->comments->create(
            $request->user(), $target, $data['body'], $data['parent_id'] ?? null, $data['mentioned_user_ids'] ?? []
        );
        $comment->load(['user:id,name', 'mentions:id,name']);

        return response()->json(['data' => $this->comments->serialize($comment, $request->user(), $target)], 201);
    }

    public function update(UpdateCommentRequest $request, Comment $comment): JsonResponse
    {
        $data = $request->validated();
        $comment = $this->comments->update(
            $comment, $request->user(), $data['body'], (int) $data['expected_lock_version'], $data['mentioned_user_ids'] ?? []
        );
        $comment->load(['user:id,name', 'mentions:id,name']);

        return response()->json(['data' => $this->comments->serialize($comment, $request->user())]);
    }

    public function destroy(DeleteCommentRequest $request, Comment $comment): JsonResponse
    {
        $comment = $this->comments->delete($comment, $request->user(), (int) $request->validated('expected_lock_version'));
        $comment->load(['user:id,name', 'mentions:id,name']);

        return response()->json(['data' => $this->comments->serialize($comment, $request->user())]);
    }

    public function restore(Request $request, Comment $comment): JsonResponse
    {
        $this->authorize('restore', $comment);
        $data = $request->validate(['expected_lock_version' => ['required', 'integer', 'min:0']]);
        $comment = $this->comments->restore($comment, $request->user(), (int) $data['expected_lock_version']);
        $comment->load(['user:id,name', 'mentions:id,name']);

        return response()->json(['data' => $this->comments->serialize($comment, $request->user())]);
    }

    public function history(Request $request, Comment $comment): JsonResponse
    {
        $this->authorize('viewHistory', $comment);

        return response()->json(['data' => $this->comments->history($comment)]);
    }
}
