<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\CommentableAccessService;
use App\Services\CommentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private array $commentAccessCache = [];

    public function __construct(private readonly CommentableAccessService $commentAccess) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->queryFor($request->user());

        return response()->json([
            'ok' => true,
            'unread_count' => (clone $query)->whereNull('read_at')->count(),
            'notifications' => $query->with('comment.commentable')->latest()->limit(10)->get()->map(function (UserNotification $notification) use ($request) {
                $canAccessComment = $this->canAccessCommentNotification($request->user(), $notification);

                return [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'title' => $canAccessComment ? $notification->title : 'Notifikasi komentar',
                    'message' => ! $canAccessComment
                        ? 'Data tidak tersedia atau Anda tidak memiliki akses.'
                        : ($this->hasDeletedComment($notification) ? CommentService::DELETED_PLACEHOLDER : $notification->message),
                    'data' => $canAccessComment ? $this->dataFor($notification) : null,
                    'action_url' => $this->actionUrlFor($request->user(), $notification),
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                    'created_label' => $notification->created_at?->diffForHumans(),
                ];
            })->values(),
        ]);
    }

    public function read(Request $request, $notification): JsonResponse
    {
        $notification = $this->queryFor($request->user())->findOrFail($notification);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->queryFor($request->user())->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function open(Request $request, $notification): RedirectResponse
    {
        $notification = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->with('comment')
            ->findOrFail($notification);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        $comment = $notification->comment;
        $target = $comment?->commentable;
        if (! $comment || ! $target
            || ! $request->user()->hasPermission('comments.view')
            || ! $this->commentAccess->canView($request->user(), $target)) {
            return redirect()->route($request->user()->landingRouteName())
                ->with('warning', 'Data tidak tersedia atau Anda tidak memiliki akses.');
        }

        $url = $this->commentAccess->targetUrl($target, 'comment-'.$comment->id);
        if (! $url) {
            return redirect()->route($request->user()->landingRouteName())
                ->with('warning', 'Data tidak tersedia atau Anda tidak memiliki akses.');
        }

        return redirect()->to($url);
    }

    private function queryFor(User $user): Builder
    {
        return UserNotification::query()->where('user_id', $user->id)
            ->when($user->isSales(), fn (Builder $query) => $query->where(function (Builder $query) {
                $query->where('type', 'feedback_status_changed')
                    ->orWhereIn('related_type', ['content_item', 'sales_lead']);
            }));
    }

    private function actionUrlFor(User $user, UserNotification $notification): ?string
    {
        if (! $user->isSales() || $this->isCommentNotification($notification)) {
            return $notification->action_url;
        }

        return match (true) {
            $notification->related_type === 'content_item' => route('content-calendar.index'),
            default => route('sales-pocketbook.index'),
        };
    }

    private function dataFor(UserNotification $notification): ?array
    {
        $data = $notification->data;
        if ($this->hasDeletedComment($notification) && is_array($data)) {
            $data['excerpt'] = CommentService::DELETED_PLACEHOLDER;
        }

        return $data;
    }

    private function hasDeletedComment(UserNotification $notification): bool
    {
        return $this->isCommentNotification($notification)
            && ($notification->comment === null || $notification->comment->trashed());
    }

    private function isCommentNotification(UserNotification $notification): bool
    {
        return in_array($notification->type, ['comment_mentioned', 'comment_replied'], true);
    }

    private function canAccessCommentNotification(User $user, UserNotification $notification): bool
    {
        if (! $this->isCommentNotification($notification)) {
            return true;
        }

        $target = $notification->comment?->commentable;
        $cacheKey = $target ? $target::class.':'.$target->getKey() : 'missing';

        return $this->commentAccessCache[$cacheKey] ??= $target !== null
            && $user->hasPermission('comments.view')
            && $this->commentAccess->canView($user, $target);
    }
}
