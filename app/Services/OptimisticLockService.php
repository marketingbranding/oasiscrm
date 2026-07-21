<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\DatabaseSheetRecord;
use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OptimisticLockService
{
    public const MESSAGE = 'Data ini telah diperbarui oleh pengguna lain setelah Anda membukanya. Muat ulang data terbaru sebelum menyimpan kembali.';

    public function __construct(private readonly CollaborationNotificationService $notifications) {}

    public function token(Model $model): string
    {
        return $model->updated_at?->copy()->utc()->format('Y-m-d H:i:s') ?? '';
    }

    public function matches(Model $model, mixed $expected): bool
    {
        if (! is_string($expected) || trim($expected) === '') {
            return false;
        }

        try {
            $expectedAt = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', trim($expected))
                ? Carbon::createFromFormat('Y-m-d H:i:s', trim($expected), 'UTC')->format('Y-m-d H:i:s')
                : Carbon::parse($expected)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return false;
        }

        return hash_equals($this->token($model), $expectedAt);
    }

    public function execute(Request $request, Model $model, mixed $expected, Closure $callback): mixed
    {
        return DB::transaction(function () use ($request, $model, $expected, $callback) {
            $current = $model->newQuery()->lockForUpdate()->findOrFail($model->getKey());
            if (! $this->matches($current, $expected)) {
                return $this->conflict($request, $current, $expected);
            }

            return $callback($current);
        });
    }

    public function conflict(Request $request, Model $model, mixed $expected): JsonResponse|RedirectResponse
    {
        $metadata = $this->metadata($model, $expected);
        Log::info('Optimistic lock conflict', [
            'operation' => 'optimistic_lock_conflict',
            'user_id' => $request->user()?->id,
            'branch_id' => $model->getAttribute('branch_id'),
            'record_type' => $metadata['record_type'],
            'record_id' => $model->getKey(),
        ]);
        if ($request->user()) {
            rescue(fn () => $this->notifications->conflict($request->user(), $model, $metadata['reload_url']), report: false);
        }

        if ($request->expectsJson()) {
            return response()->json($metadata, 409);
        }

        return back()->withInput()->with('conflict', self::MESSAGE)->with('conflict_data', $metadata);
    }

    public function metadata(Model $model, mixed $expected): array
    {
        $modifier = $this->modifier($model);

        return [
            'ok' => false,
            'code' => 'record_modified',
            'message' => self::MESSAGE,
            'record_type' => $this->recordType($model),
            'record_id' => $model->getKey(),
            'expected_updated_at' => is_scalar($expected) ? (string) $expected : null,
            'current_updated_at' => $this->token($model),
            'current_updated_label' => $model->updated_at?->copy()->locale('id')->translatedFormat('d M Y, H:i'),
            'modified_by' => $modifier ? [
                'user_id' => $modifier->id,
                'display_name' => $modifier->name,
            ] : null,
            'reload_url' => $this->reloadUrl($model),
        ];
    }

    private function modifier(Model $model): ?User
    {
        if (! filled($model->getAttribute('updated_by')) || ! method_exists($model, 'updatedBy')) {
            return null;
        }

        return $model->updatedBy()->first(['id', 'name']);
    }

    private function recordType(Model $model): string
    {
        return match (true) {
            $model instanceof DanaTalangan => 'dana_talangan',
            $model instanceof ContentItem => 'content_item',
            $model instanceof DatabaseSheetRecord => 'database_sheet_record',
            default => class_basename($model),
        };
    }

    private function reloadUrl(Model $model): ?string
    {
        return match (true) {
            $model instanceof DanaTalangan => route('dana-talangan.edit', $model),
            $model instanceof ContentItem => route('content-calendar.edit', $model),
            $model instanceof DatabaseSheetRecord => route('database.index', [
                'branch_id' => $model->branch_id,
                'sheet' => $model->sheet_name,
            ]),
            default => null,
        };
    }
}
