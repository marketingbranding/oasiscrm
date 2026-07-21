<?php

namespace App\Console\Commands;

use App\Models\SystemTaskRun;
use App\Models\User;
use App\Models\UserPresence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PresenceDiagnostics extends Command
{
    protected $signature = 'oasis:presence-diagnostics {--benchmark=0 : Insert and roll back synthetic rows for an optional query benchmark}';

    protected $description = 'Show aggregate presence storage and cleanup metrics';

    public function handle(): int
    {
        $offlineCutoff = now()->subSeconds((int) config('presence.offline_seconds', 60));
        $cleanupCutoff = now()->subHours((int) config('presence.cleanup_hours', 24));
        $latestCleanup = SystemTaskRun::where('task_key', 'oasis:presence-cleanup')->where('status', 'success')->latest('finished_at')->first();

        $this->table(['Metric', 'Value'], [
            ['Total rows', UserPresence::count()],
            ['Active rows', UserPresence::where('last_seen_at', '>=', $offlineCutoff)->count()],
            ['Stale rows', UserPresence::where('last_seen_at', '<', $offlineCutoff)->count()],
            ['Cleanup eligible', UserPresence::where('last_seen_at', '<', $cleanupCutoff)->count()],
            ['Oldest row', UserPresence::min('last_seen_at') ?: '-'],
            ['Latest cleanup', $latestCleanup?->finished_at?->toDateTimeString() ?: 'Never'],
        ]);

        $benchmarkRows = min(200000, max(0, (int) $this->option('benchmark')));
        if ($benchmarkRows > 0) {
            $this->benchmark($benchmarkRows);
        }

        return self::SUCCESS;
    }

    private function benchmark(int $rowCount): void
    {
        $userId = User::value('id');
        if (! $userId) {
            $this->warn('Benchmark skipped because no user exists.');

            return;
        }

        $prefix = 'benchmark_'.Str::lower(Str::random(8));
        DB::beginTransaction();
        try {
            foreach (range(0, $rowCount - 1, 100) as $offset) {
                $rows = [];
                for ($index = $offset; $index < min($offset + 100, $rowCount); $index++) {
                    $rows[] = [
                        'user_id' => $userId,
                        'branch_id' => null,
                        'page_key' => $prefix,
                        'context_key' => 'page',
                        'mode' => 'viewing',
                        'session_key' => $prefix.'_'.$index,
                        'last_seen_at' => now()->subHours(25),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                DB::table('user_presences')->insert($rows);
            }
            $started = microtime(true);
            $eligible = UserPresence::where('last_seen_at', '<', now()->subHours((int) config('presence.cleanup_hours', 24)))->count();
            $duration = round((microtime(true) - $started) * 1000, 2);
            $this->info("Benchmark: {$rowCount} synthetic rows, cleanup eligibility query {$duration} ms, {$eligible} total eligible rows. All synthetic rows rolled back.");
        } finally {
            DB::rollBack();
        }
    }
}
