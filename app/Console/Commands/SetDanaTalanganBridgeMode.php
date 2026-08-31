<?php

namespace App\Console\Commands;

use App\Enums\DanaTalanganBridgeMode;
use App\Services\DanaTalanganBridgeModeService;
use App\Services\DanaTalanganBridgeService;
use Illuminate\Console\Command;
use Throwable;

class SetDanaTalanganBridgeMode extends Command
{
    protected $signature = 'dana-talangan-bridge:set-mode {--mode=}';

    protected $description = 'Atur mode bridge Dana Talangan global';

    public function handle(): int
    {
        try {
            $mode = DanaTalanganBridgeMode::from((string) $this->option('mode'));
            if ($mode !== DanaTalanganBridgeMode::Off && ! config('services.google_sheets.dana_talangan_bridge_enabled')) {
                throw new \DomainException('Bridge Dana Talangan sedang dinonaktifkan.');
            }
            if (blank(config('services.google_sheets.dana_talangan_spreadsheet_id'))) {
                throw new \DomainException('DANA_TALANGAN_SHEET_ID belum dikonfigurasi.');
            }
            if ($mode !== DanaTalanganBridgeMode::Off) {
                app(DanaTalanganBridgeService::class)->preflight();
            }
            $setting = app(DanaTalanganBridgeModeService::class)->setMode($mode);
        } catch (Throwable $exception) {
            $this->error($exception instanceof \DomainException ? $exception->getMessage() : 'Mode bridge Dana Talangan tidak dapat diubah.');

            return self::FAILURE;
        }
        $this->info('Dana Talangan: '.$setting->mode->value);

        return self::SUCCESS;
    }
}
