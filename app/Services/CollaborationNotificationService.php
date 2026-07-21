<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\DatabaseSheetRecord;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserPresence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Throwable;

class CollaborationNotificationService
{
    public function __construct(private readonly WorkspaceAccessService $workspaceAccess) {}

    public function create(
        User $user,
        string $type,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?Model $related = null,
    ): UserNotification {
        return UserNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'action_url' => $actionUrl,
            'related_type' => $related ? $this->recordType($related) : null,
            'related_id' => $related?->getKey(),
        ]);
    }

    public function conflict(User $user, Model $record, ?string $actionUrl): void
    {
        $this->attempt(function () use ($user, $record, $actionUrl) {
            $existing = UserNotification::where('user_id', $user->id)
                ->where('type', 'record_conflict')
                ->where('related_type', $this->recordType($record))
                ->where('related_id', $record->getKey())
                ->where('created_at', '>=', now()->subMinutes(5))
                ->exists();
            if (! $existing) {
                $this->create(
                    $user, 'record_conflict', 'Perubahan tidak tersimpan',
                    'Data telah diperbarui pengguna lain. Muat ulang data terbaru sebelum menyimpan kembali.',
                    $actionUrl, $record,
                );
            }
        });
    }

    public function recordUpdated(Model $record, User $actor, string $actionUrl): void
    {
        $this->attempt(function () use ($record, $actor, $actionUrl) {
            $recordType = $this->recordType($record);
            $recipientIds = UserPresence::query()
                ->where('record_type', $recordType)
                ->where('record_id', $record->getKey())
                ->where('last_seen_at', '>=', now()->subSeconds((int) config('presence.offline_seconds', 60)))
                ->where('user_id', '!=', $actor->id)
                ->distinct()
                ->pluck('user_id');

            User::where('is_active', true)->whereIn('id', $recipientIds)->each(function (User $user) use ($record, $actor, $actionUrl) {
                if (! $this->canViewRecord($user, $record)) {
                    return;
                }
                $this->create($user, 'record_updated', 'Data diperbarui', $actor->name.' memperbarui '.$this->recordLabel($record).'.', $actionUrl, $record);
            });
        });
    }

    public function membershipChanged(User $user, string $message, ?string $actionUrl = null): void
    {
        $this->attempt(fn () => $this->create($user, 'membership_changed', 'Akses cabang diperbarui', $message, $actionUrl, $user));
    }

    public function syncResult(User $user, string $module, ?string $scope, array $result, string $actionUrl, int $rowCount = 0): void
    {
        $ok = (bool) ($result['ok'] ?? false);
        $scopeLabel = $scope ? ' '.$scope : '';
        $message = $ok
            ? "Sinkronisasi {$module}{$scopeLabel} selesai pada ".now()->format('d M Y, H:i').($rowCount > 0 ? ": {$rowCount} baris diperbarui." : '.')
            : "Sinkronisasi {$module}{$scopeLabel} gagal pada ".now()->format('d M Y, H:i').'. '.str((string) ($result['message'] ?? 'Kesalahan tidak diketahui.'))->limit(300);

        $this->attempt(fn () => $this->create(
            $user,
            $ok ? 'sync_completed' : 'sync_failed',
            $ok ? 'Sinkronisasi selesai' : 'Sinkronisasi gagal',
            $message,
            $actionUrl,
        ));
    }

    public function recordType(Model $record): string
    {
        return match (true) {
            $record instanceof DanaTalangan => 'dana_talangan',
            $record instanceof ContentItem => 'content_item',
            $record instanceof DatabaseSheetRecord => 'database_sheet_record',
            $record instanceof User => 'user',
            default => class_basename($record),
        };
    }

    private function recordLabel(Model $record): string
    {
        return match (true) {
            $record instanceof DanaTalangan => 'Dana Talangan atas nama '.($record->nama_konsumen ?: 'tanpa nama'),
            $record instanceof ContentItem => 'Work Planner "'.($record->title ?: 'tanpa judul').'"',
            $record instanceof DatabaseSheetRecord => 'baris Database '.$record->sheet_name,
            default => 'data',
        };
    }

    private function canViewRecord(User $user, Model $record): bool
    {
        if ($record instanceof ContentItem) {
            return Gate::forUser($user)->allows('view', $record);
        }

        if ($record instanceof DanaTalangan || $record instanceof DatabaseSheetRecord) {
            return filled($record->branch_id) && $this->workspaceAccess->canViewBranch($user, (int) $record->branch_id);
        }

        return false;
    }

    private function attempt(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
