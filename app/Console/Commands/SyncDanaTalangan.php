<?php

namespace App\Console\Commands;

use App\Services\DanaTalanganBridgeService;
use Illuminate\Console\Command;

class SyncDanaTalangan extends Command
{
    protected $signature = 'dana-talangan:sync {--dry-run : Preview changes without writing data}';

    protected $description = 'Synchronize Dana Talangan with the canonical Google Sheets tab';

    public function handle(): int
    {
        if (! config('oasis.spreadsheet_sync_enabled')) {
            $this->warn('Sinkronisasi spreadsheet OASIS berstatus dormant dan dilewati.');

            return self::SUCCESS;
        }
        if (! config('services.google_sheets.dana_talangan_bridge_enabled')) {
            $this->warn('Bridge Dana Talangan sedang dinonaktifkan.');

            return self::SUCCESS;
        }
        if (blank(config('services.google_sheets.dana_talangan_spreadsheet_id'))) {
            $this->error('DANA_TALANGAN_SHEET_ID belum dikonfigurasi.');

            return self::FAILURE;
        }
        $result = app(DanaTalanganBridgeService::class)->pull(null, (bool) $this->option('dry-run'));
        if (($result['status'] ?? null) === 'disabled') {
            $this->warn('Mode bridge Dana Talangan belum bidirectional.');

            return self::SUCCESS;
        }
        if (! $result['ok']) {
            $this->error($result['message'] ?? 'Sinkronisasi bridge Dana Talangan gagal.');

            return self::FAILURE;
        }

        $this->table(array_keys($result['summary']), [array_values($result['summary'])]);

        return self::SUCCESS;
    }
}
