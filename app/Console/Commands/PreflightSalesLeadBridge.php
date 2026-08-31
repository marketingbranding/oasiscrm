<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\SalesLeadBridgeService;
use Illuminate\Console\Command;
use Throwable;

class PreflightSalesLeadBridge extends Command
{
    protected $signature = 'sales-lead-bridge:preflight {--branch=}';

    protected $description = 'Periksa kontrak tab lead untuk satu cabang aktif';

    public function handle(): int
    {
        $branchId = $this->option('branch');
        $branch = $branchId ? Branch::whereKey((int) $branchId)->where('is_active', true)->first() : null;
        if ($branch === null) {
            $this->error('--branch harus menunjuk cabang aktif.');

            return self::FAILURE;
        }
        if (! config('services.google_sheets.sales_lead_sync_enabled')) {
            $this->warn('Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.');

            return self::SUCCESS;
        }
        try {
            app(SalesLeadBridgeService::class)->preflight($branch);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Preflight bridge lead gagal.');

            return self::FAILURE;
        }
        $this->info($branch->name.': OK');

        return self::SUCCESS;
    }
}
