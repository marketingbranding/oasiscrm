<?php

namespace App\Console\Commands;

use App\Services\SalesLeadLifecycleSyncService;
use Illuminate\Console\Command;

class SyncSalesLeadLifecycle extends Command
{
    protected $signature = 'sales-lead-lifecycle:sync {--branch= : ID cabang yang akan disinkronkan}';

    protected $description = 'Tarik dan rekonsiliasi siklus lead Buku Saku Sales per cabang';

    public function handle(SalesLeadLifecycleSyncService $service): int
    {
        $results = $service->syncAll($this->option('branch') ? (int) $this->option('branch') : null);
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
