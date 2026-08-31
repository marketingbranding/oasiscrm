<?php

namespace App\Console\Commands;

use App\Services\DanaTalanganBridgeService;
use Illuminate\Console\Command;
use Throwable;

class SyncDanaTalanganBridge extends Command
{
    protected $signature = 'dana-talangan-bridge:sync {--dry-run}';

    protected $description = 'Tarik perubahan aman tab Talangan ke OASIS';

    public function handle(): int
    {
        if (! config('services.google_sheets.dana_talangan_bridge_enabled')) {
            $this->warn('Bridge Dana Talangan sedang dinonaktifkan.');

            return self::SUCCESS;
        }
        if (blank(config('services.google_sheets.dana_talangan_spreadsheet_id'))) {
            $this->error('DANA_TALANGAN_SHEET_ID belum dikonfigurasi.');

            return self::FAILURE;
        }
        try {
            $result = app(DanaTalanganBridgeService::class)->pull(null, (bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Sinkronisasi bridge Dana Talangan gagal.');

            return self::FAILURE;
        }
        if (($result['status'] ?? null) === 'disabled') {
            $this->warn('Mode bridge Dana Talangan belum bidirectional.');

            return self::SUCCESS;
        }
        $this->line('Dana Talangan: '.strtoupper($result['status']));
        $this->table(array_keys($result['summary']), [array_values($result['summary'])]);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
