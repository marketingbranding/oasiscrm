<?php

namespace App\Console\Commands;

use App\Services\DanaTalanganBridgeService;
use Illuminate\Console\Command;
use Throwable;

class PreflightDanaTalanganBridge extends Command
{
    protected $signature = 'dana-talangan-bridge:preflight';

    protected $description = 'Periksa kontrak tab Talangan tanpa mengubah workbook';

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
            $setting = app(DanaTalanganBridgeService::class)->preflight();
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Preflight bridge Dana Talangan gagal.');

            return self::FAILURE;
        }
        $this->info('Talangan: OK '.$setting->preflight_hash);

        return self::SUCCESS;
    }
}
