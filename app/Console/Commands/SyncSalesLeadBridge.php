<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\SalesLeadBridgeService;
use Illuminate\Console\Command;
use Throwable;

class SyncSalesLeadBridge extends Command
{
    protected $signature = 'sales-lead-bridge:sync {--branch= : ID cabang aktif} {--dry-run}';

    protected $description = 'Tarik perubahan tab lead ke OASIS secara manual';

    public function handle(): int
    {
        $branchId = $this->option('branch');
        if (! $branchId) {
            $this->error('--branch wajib diisi.');

            return self::FAILURE;
        }
        $branch = Branch::whereKey((int) $branchId)->where('is_active', true)->first();
        if ($branch === null) {
            $this->error('Cabang aktif tidak ditemukan.');

            return self::FAILURE;
        }
        if (! config('services.google_sheets.sales_lead_sync_enabled')) {
            $this->warn('Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.');

            return self::SUCCESS;
        }
        try {
            $result = app(SalesLeadBridgeService::class)->pull($branch, null, (bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Sinkronisasi bridge gagal.');

            return self::FAILURE;
        }
        if ($result['status'] === 'disabled') {
            $this->warn('Bridge lead tidak aktif pada cabang tersebut.');

            return self::SUCCESS;
        }
        $this->line($branch->name.': '.strtoupper($result['status']));
        $this->table(array_keys($result['summary']), [array_values($result['summary'])]);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
