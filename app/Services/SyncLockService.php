<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class SyncLockService
{
    public function run(string $key, callable $callback, int $seconds = 600): array
    {
        $lock = Cache::lock('oasis:sync:'.$key, $seconds);
        if (! $lock->get()) {
            return [
                'ok' => false,
                'status' => 'syncing',
                'code' => 'sync_already_running',
                'message' => 'Sinkronisasi untuk scope ini sedang berjalan.',
                'retryable' => true,
                'summary' => [],
            ];
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
