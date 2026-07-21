<?php

namespace App\Services;

use App\Models\SystemTaskRun;
use App\Models\UserNotification;
use App\Models\UserPresence;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SystemHealthService
{
    public function report(): array
    {
        return [
            'application' => [
                $this->result('Application environment', 'pass', app()->environment()),
                $this->databaseCheck(),
                $this->migrationCheck(),
                $this->result('Queue driver', 'warning', 'Configured: '.config('queue.default').'; worker liveness is not measured'),
                $this->result('Session driver', 'warning', 'Configured: '.config('session.driver')),
                $this->result('Cache driver', 'warning', 'Configured: '.config('cache.default')),
            ],
            'scheduler' => $this->schedulerChecks(),
            'presence' => $this->presenceChecks(),
            'notifications' => $this->notificationChecks(),
            'storage' => $this->storageChecks(),
        ];
    }

    private function databaseCheck(): array
    {
        try {
            DB::select('select 1');

            return $this->result('Database connectivity', 'pass', 'Database connected');
        } catch (Throwable) {
            return $this->result('Database connectivity', 'fail', 'Database connection failed');
        }
    }

    private function migrationCheck(): array
    {
        try {
            $files = collect(File::files(database_path('migrations')))
                ->map(fn ($file) => pathinfo($file->getFilename(), PATHINFO_FILENAME));
            $ran = DB::table('migrations')->pluck('migration');
            $pending = $files->diff($ran)->count();

            return $this->result('Migrations', $pending === 0 ? 'pass' : 'warning', $pending === 0 ? 'Migrations current' : "{$pending} migrations pending");
        } catch (Throwable) {
            return $this->result('Migrations', 'fail', 'Migration status unavailable');
        }
    }

    private function schedulerChecks(): array
    {
        $events = collect(app(Schedule::class)->events());
        $presenceRegistered = $events->contains(fn ($event) => str_contains((string) $event->command, 'oasis:presence-cleanup'));
        $notificationsRegistered = $events->contains(fn ($event) => str_contains((string) $event->command, 'oasis:notifications-cleanup'));
        $checks = [
            $this->result('Presence cleanup schedule', $presenceRegistered ? 'pass' : 'fail', $presenceRegistered ? 'Registered hourly' : 'Not registered'),
            $this->result('Notification cleanup schedule', $notificationsRegistered ? 'pass' : 'fail', $notificationsRegistered ? 'Registered weekly' : 'Not registered'),
        ];

        if (! Schema::hasTable('system_task_runs')) {
            $checks[] = $this->result('Presence cleanup execution', 'fail', 'Task run table unavailable');

            return $checks;
        }

        $lastSuccess = SystemTaskRun::where('task_key', 'oasis:presence-cleanup')->where('status', 'success')->latest('finished_at')->first();
        $lastFailure = SystemTaskRun::where('task_key', 'oasis:presence-cleanup')->where('status', 'failed')->latest('finished_at')->first();
        $stale = ! $lastSuccess || $lastSuccess->finished_at?->lt(now()->subHours(2));
        $checks[] = $this->result(
            'Presence cleanup execution',
            $stale ? 'warning' : 'pass',
            $lastSuccess ? 'Last success '.$lastSuccess->finished_at->diffForHumans().' ('.($lastSuccess->summary['rows_deleted'] ?? 0).' rows deleted)' : 'No successful run recorded',
        );
        $unrecoveredFailure = $lastFailure && (! $lastSuccess || $lastFailure->finished_at?->gt($lastSuccess->finished_at));
        $checks[] = $this->result('Last cleanup failure', $unrecoveredFailure ? 'warning' : 'pass', $unrecoveredFailure ? $lastFailure->finished_at?->diffForHumans() : 'No unrecovered failure');

        $notificationSuccess = SystemTaskRun::where('task_key', 'oasis:notifications-cleanup')->where('status', 'success')->latest('finished_at')->first();
        $notificationStale = ! $notificationSuccess || $notificationSuccess->finished_at?->lt(now()->subDays(8));
        $checks[] = $this->result(
            'Notification cleanup execution',
            $notificationStale ? 'warning' : 'pass',
            $notificationSuccess ? 'Last success '.$notificationSuccess->finished_at->diffForHumans() : 'No successful run recorded',
        );

        return $checks;
    }

    private function presenceChecks(): array
    {
        if (! Schema::hasTable('user_presences')) {
            return [$this->result('Presence table', 'fail', 'Table unavailable')];
        }

        $offlineCutoff = now()->subSeconds((int) config('presence.offline_seconds', 60));
        $cleanupCutoff = now()->subHours((int) config('presence.cleanup_hours', 24));
        $total = UserPresence::count();
        $active = UserPresence::where('last_seen_at', '>=', $offlineCutoff)->count();
        $stale = UserPresence::where('last_seen_at', '<', $offlineCutoff)->count();
        $eligible = UserPresence::where('last_seen_at', '<', $cleanupCutoff)->count();
        $oldest = UserPresence::min('last_seen_at');
        $timingSafe = (int) config('presence.offline_seconds', 60) > (int) config('presence.heartbeat_seconds', 25);

        return [
            $this->result('Presence timing', $timingSafe ? 'pass' : 'warning', $timingSafe ? 'Offline threshold exceeds heartbeat interval' : 'Offline threshold must exceed heartbeat interval'),
            $this->result('Presence rows', $eligible > 10000 ? 'warning' : 'pass', "Total {$total}; active {$active}; stale {$stale}; cleanup eligible {$eligible}"),
            $this->result('Oldest presence row', 'pass', $oldest ?: 'No rows'),
        ];
    }

    private function notificationChecks(): array
    {
        if (! Schema::hasTable('user_notifications')) {
            return [$this->result('Notification table', 'fail', 'Table unavailable')];
        }

        $unread = UserNotification::whereNull('read_at')->count();
        $read = UserNotification::whereNotNull('read_at')->count();
        $eligible = UserNotification::whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays((int) config('notifications.read_retention_days', 180)))
            ->count();

        return [
            $this->result('Notification table', 'pass', 'Table available'),
            $this->result('Notification summary', $eligible > 10000 ? 'warning' : 'pass', "Unread {$unread}; read {$read}; retention eligible {$eligible}"),
        ];
    }

    private function storageChecks(): array
    {
        $manifest = (string) config('health.vite_manifest_path', public_path('build/manifest.json'));
        $status = 'fail';
        $message = 'Vite manifest missing';
        if (is_readable($manifest)) {
            try {
                $assets = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
                $buildPath = dirname($manifest);
                $missing = collect($assets)->pluck('file')->filter(fn ($file) => ! is_file($buildPath.DIRECTORY_SEPARATOR.$file))->count();
                $status = $missing === 0 ? 'pass' : 'warning';
                $message = $missing === 0 ? 'Vite manifest and assets available' : "{$missing} manifest assets missing";
            } catch (Throwable) {
                $message = 'Vite manifest is invalid';
            }
        }

        return [
            $this->result('Storage writability', is_writable(storage_path()) ? 'pass' : 'fail', is_writable(storage_path()) ? 'Storage writable' : 'Storage not writable'),
            $this->result('Bootstrap cache writability', is_writable(base_path('bootstrap/cache')) ? 'pass' : 'fail', is_writable(base_path('bootstrap/cache')) ? 'Bootstrap cache writable' : 'Bootstrap cache not writable'),
            $this->result('Vite assets', $status, $message),
        ];
    }

    private function result(string $label, string $status, string $message): array
    {
        return compact('label', 'status', 'message');
    }
}
