<?php

namespace App\Console\Commands;

use App\Services\KonsumenProgressSyncService;
use Illuminate\Console\Command;

class SyncKonsumenProgress extends Command
{
    protected $signature = 'konsumen-progress:sync {--branch= : ID branch yang akan disinkronkan}';

    protected $description = 'Sinkronkan cache lokal Konsumen Progress dari Google Sheets API';

    public function handle(KonsumenProgressSyncService $syncService): int
    {
        $branchId = $this->option('branch') ? (int) $this->option('branch') : null;
        $results = $syncService->syncAll($branchId);

        if (empty($results)) {
            $this->warn('Tidak ada branch aktif dengan sheet_id untuk disinkronkan.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($results as $result) {
            $totalRows = array_sum($result['summary'] ?? []);
            if ($result['ok']) {
                $this->info($result['branch'] . ': OK, ' . count($result['summary']) . ' sheets, ' . $totalRows . ' rows');
            } else {
                $failed = true;
                $this->error($result['branch'] . ': FAILED, ' . $result['message']);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
