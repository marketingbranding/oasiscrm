<?php

namespace App\Console\Commands;

use App\Models\SystemTaskRun;
use App\Models\UserNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanupNotifications extends Command
{
    protected $signature = 'oasis:notifications-cleanup {--dry-run} {--days=}';

    protected $description = 'Delete read user notifications older than the retention policy';

    public function handle(): int
    {
        $run = null;
        try {
            $run = SystemTaskRun::create([
                'task_key' => 'oasis:notifications-cleanup',
                'started_at' => now(),
                'status' => 'running',
            ]);
        } catch (Throwable $exception) {
            Log::warning('Notification cleanup task recording unavailable', [
                'operation' => 'notifications_cleanup_record_start',
                'error_class' => $exception::class,
            ]);
        }

        try {
            $days = max(1, (int) ($this->option('days') ?: config('notifications.read_retention_days', 180)));
            $query = UserNotification::whereNotNull('read_at')->where('read_at', '<', now()->subDays($days));
            $eligible = (clone $query)->count();

            if ($this->option('dry-run')) {
                $run?->update(['finished_at' => now(), 'status' => 'success', 'summary' => ['dry_run' => true, 'eligible' => $eligible, 'deleted' => 0]]);
                $this->info("Dry run: {$eligible} read notifications are eligible for deletion after {$days} days.");

                return self::SUCCESS;
            }

            $deleted = 0;
            $query->select('id')->chunkById(1000, function ($rows) use (&$deleted) {
                $ids = $rows->pluck('id');
                $deleted += UserNotification::whereIn('id', $ids)->whereNotNull('read_at')->delete();
            });
            $run?->update(['finished_at' => now(), 'status' => 'success', 'summary' => ['dry_run' => false, 'eligible' => $eligible, 'deleted' => $deleted]]);
            $this->info("{$deleted} read notifications deleted. Unread notifications were retained.");

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
            Log::error('Notification cleanup failed', [
                'operation' => 'notifications_cleanup',
                'error_class' => $exception::class,
            ]);
            $this->error('Notification cleanup failed. See server logs.');

            return self::FAILURE;
        }
    }
}
