<?php

namespace App\Console\Commands;

use App\Models\SalesLeadBridgeSetting;
use App\Services\SalesLeadLifecycleSyncService;
use Illuminate\Console\Command;

class SyncSalesLeadLifecycle extends Command
{
    protected $signature = 'sales-lead-lifecycle:sync {--branch= : ID cabang yang akan disinkronkan}';

    protected $description = 'Tarik dan rekonsiliasi siklus lead Buku Saku Sales per cabang';

    public function handle(): int
    {
        if (! config('services.google_sheets.sales_lead_sync_enabled')) {
            $this->warn('Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.');

            return self::SUCCESS;
        }
        $branchId = $this->option('branch') ? (int) $this->option('branch') : null;
        $enabledBranchIds = SalesLeadBridgeSetting::query()->where('mode', '!=', 'off')->pluck('branch_id')->map(fn ($id) => (int) $id)->all();
        if ($branchId !== null && in_array($branchId, $enabledBranchIds, true)) {
            $this->warn('Cabang bridge aktif dilewati agar tab lead tidak dimutasi otomatis. Sinkronisasi lifecycle downstream manual masih menjadi debt.');

            return self::SUCCESS;
        }
        if ($enabledBranchIds !== []) {
            $this->warn('Cabang bridge aktif dilewati agar tab lead tidak dimutasi otomatis. Sinkronisasi lifecycle downstream manual masih menjadi debt.');
        }
        $results = app(SalesLeadLifecycleSyncService::class)->syncAll($branchId, $enabledBranchIds);
        if ($results === []) {
            $this->warn('Tidak ada cabang aktif dengan sheet_id untuk disinkronkan.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($results as $result) {
            if ($result['ok']) {
                $this->info($result['branch'].': OK');
            } else {
                $failed = true;
                $this->error($result['branch'].': FAILED, '.$result['message']);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
