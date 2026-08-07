<?php

namespace App\Console\Commands;

use App\Services\SalesLeadSyncService;
use Illuminate\Console\Command;

class SyncSalesLead extends Command
{
    protected $signature = 'sales-lead:sync {--branch= : ID cabang yang akan disinkronkan}';

    protected $description = 'Tarik dan rekonsiliasi tab lead Buku Saku Sales per cabang';

    public function handle(SalesLeadSyncService $service): int
    {
        $branchId = $this->option('branch') ? (int) $this->option('branch') : null;
        $results = $service->syncAll($branchId);
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
