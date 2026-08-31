<?php

namespace App\Console\Commands;

use App\Enums\SalesLeadBridgeMode;
use App\Models\Branch;
use App\Services\SalesLeadBridgeModeService;
use App\Services\SalesLeadBridgeService;
use Illuminate\Console\Command;
use Throwable;

class SetSalesLeadBridgeMode extends Command
{
    protected $signature = 'sales-lead-bridge:set-mode {--branch=} {--mode=}';

    protected $description = 'Atur mode bridge lead untuk satu cabang aktif';

    public function handle(): int
    {
        $branchId = $this->option('branch');
        $branch = $branchId ? Branch::whereKey((int) $branchId)->where('is_active', true)->first() : null;
        if ($branch === null) {
            $this->error('--branch harus menunjuk cabang aktif.');

            return self::FAILURE;
        }
        try {
            $mode = SalesLeadBridgeMode::from((string) $this->option('mode'));
            if ($mode !== SalesLeadBridgeMode::Off && ! config('services.google_sheets.sales_lead_sync_enabled')) {
                throw new \DomainException('Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.');
            }
            if ($mode !== SalesLeadBridgeMode::Off) {
                app(SalesLeadBridgeService::class)->preflight($branch);
            }
            $setting = app(SalesLeadBridgeModeService::class)->setMode($branch, $mode);
        } catch (Throwable $exception) {
            $this->error($exception instanceof \DomainException ? $exception->getMessage() : 'Mode bridge tidak dapat diubah.');

            return self::FAILURE;
        }
        $this->info($branch->name.': '.$setting->mode->value);

        return self::SUCCESS;
    }
}
