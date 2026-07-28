<?php

namespace App\Console\Commands;

use App\Models\UserImportBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanupUserImportBatches extends Command
{
    protected $signature = 'oasis:user-import-cleanup {--dry-run}';

    protected $description = 'Delete expired unconfirmed user import staging batches';

    public function handle(): int
    {
        try {
            $statuses = [
                UserImportBatch::STATUS_DRAFT,
                UserImportBatch::STATUS_VALIDATING,
                UserImportBatch::STATUS_READY,
                UserImportBatch::STATUS_PREVIEW_READY,
                UserImportBatch::STATUS_VALIDATION_FAILED,
            ];
            $query = UserImportBatch::query()->whereIn('status', $statuses)
                ->whereNull('confirmed_at')->whereNotNull('expires_at')->where('expires_at', '<=', now());
            $eligibleBatches = (clone $query)->count();
            $eligibleRows = (clone $query)->withCount('rows')->get()->sum('rows_count');

            if ($this->option('dry-run')) {
                Log::info('User import staging cleanup dry run', [
                    'eligible_batches' => $eligibleBatches,
                    'eligible_rows' => $eligibleRows,
                    'deleted_batches' => 0,
                ]);
                $this->info("Dry run: {$eligibleBatches} batches and {$eligibleRows} staging rows are eligible for deletion.");

                return self::SUCCESS;
            }

            $deleted = 0;
            $query->select('id')->chunkById(200, function ($batches) use (&$deleted, $statuses) {
                $ids = $batches->pluck('id');
                $deleted += UserImportBatch::query()->whereIn('id', $ids)->whereIn('status', $statuses)
                    ->whereNull('confirmed_at')->where('expires_at', '<=', now())->delete();
            });
            Log::info('User import staging cleanup completed', [
                'eligible_batches' => $eligibleBatches,
                'eligible_rows' => $eligibleRows,
                'deleted_batches' => $deleted,
            ]);
            $this->info("{$deleted} expired staging batches deleted with {$eligibleRows} staged rows.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('User import staging cleanup failed', ['error_class' => $exception::class]);
            $this->error('User import staging cleanup failed. See server logs.');

            return self::FAILURE;
        }
    }
}
