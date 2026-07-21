<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OptimisticLockService
{
    public const MESSAGE = 'Data ini telah diperbarui oleh pengguna lain. Muat ulang data sebelum menyimpan kembali.';

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

    public function conflict(Request $request, Model $model, mixed $expected): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'code' => 'record_modified',
                'message' => self::MESSAGE,
                'current_updated_at' => $this->token($model),
                'expected_updated_at' => is_scalar($expected) ? (string) $expected : null,
            ], 409);
        }

        return back()->withInput()->with('conflict', self::MESSAGE)->with('error', self::MESSAGE);
    }
}
