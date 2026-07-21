<?php

namespace App\Console\Commands;

use App\Models\SystemTaskRun;
use App\Models\UserPresence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanupPresence extends Command
{
    protected $signature = 'oasis:presence-cleanup';

    protected $description = 'Delete stale CRM presence rows';

    public function handle(): int
    {
        $run = null;
        try {
            $run = SystemTaskRun::create([
                'task_key' => 'oasis:presence-cleanup',
                'started_at' => now(),
                'status' => 'running',
            ]);
        } catch (Throwable $exception) {
            Log::warning('Presence cleanup task recording unavailable', [
                'operation' => 'presence_cleanup_record_start',
                'error_class' => $exception::class,
            ]);
        }

        try {
            $deleted = UserPresence::where('last_seen_at', '<', now()->subHours((int) config('presence.cleanup_hours', 24)))->delete();
            $run?->update([
                'finished_at' => now(),
                'status' => 'success',
                'summary' => ['rows_deleted' => $deleted],
            ]);
            $this->info($deleted.' stale presence rows deleted.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            try {
                $run?->update([
                    'finished_at' => now(),
                    'status' => 'failed',
                    'error_message' => str($exception->getMessage())->limit(500),
                ]);
            } catch (Throwable) {
                // Preserve the original cleanup failure.
            }
            Log::error('Presence cleanup failed', [
                'operation' => 'presence_cleanup',
                'error_class' => $exception::class,
            ]);
            $this->error('Presence cleanup failed. See server logs.');

            return self::FAILURE;
        }
    }
}
