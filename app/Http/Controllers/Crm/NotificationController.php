<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->queryFor($request->user());

        return response()->json([
            'ok' => true,
            'unread_count' => (clone $query)->whereNull('read_at')->count(),
            'notifications' => $query->latest()->limit(10)->get()->map(fn (UserNotification $notification) => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message,
                'action_url' => $this->actionUrlFor($request->user(), $notification),
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
                'created_label' => $notification->created_at?->diffForHumans(),
            ])->values(),
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
        if (! $user->isSales()) {
            return $notification->action_url;
        }

        return match (true) {
            $notification->related_type === 'content_item' => route('content-calendar.index'),
            default => route('sales-pocketbook.index'),
        };
    }
}
