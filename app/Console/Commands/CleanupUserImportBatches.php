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
            $expiredCredentials = UserImportBatch::query()->whereNotNull('credential_payload')
                ->whereNotNull('credential_expires_at')->where('credential_expires_at', '<=', now())->count();

            if ($this->option('dry-run')) {
                Log::info('User import staging cleanup dry run', [
                    'eligible_batches' => $eligibleBatches,
                    'eligible_rows' => $eligibleRows,
                    'deleted_batches' => 0,
                    'expired_credentials' => $expiredCredentials,
                    'cleared_credentials' => 0,
                ]);
                $this->info("Dry run: {$eligibleBatches} batches and {$eligibleRows} staging rows are eligible for deletion; {$expiredCredentials} credential payloads are eligible for clearing.");

                return self::SUCCESS;
            }

            $deleted = 0;
            $query->select('id')->chunkById(200, function ($batches) use (&$deleted, $statuses) {
                $ids = $batches->pluck('id');
                $deleted += UserImportBatch::query()->whereIn('id', $ids)->whereIn('status', $statuses)
                    ->whereNull('confirmed_at')->where('expires_at', '<=', now())->delete();
            });
            $clearedCredentials = UserImportBatch::query()->whereNotNull('credential_payload')
                ->whereNotNull('credential_expires_at')->where('credential_expires_at', '<=', now())
                ->update(['credential_payload' => null]);
            Log::info('User import staging cleanup completed', [
                'eligible_batches' => $eligibleBatches,
                'eligible_rows' => $eligibleRows,
                'deleted_batches' => $deleted,
                'expired_credentials' => $expiredCredentials,
                'cleared_credentials' => $clearedCredentials,
            ]);
            $this->info("{$deleted} expired staging batches deleted with {$eligibleRows} staged rows; {$clearedCredentials} credential payloads cleared.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('User import staging cleanup failed', ['error_class' => $exception::class]);
            $this->error('User import staging cleanup failed. See server logs.');

            return self::FAILURE;
        }
    }
}
