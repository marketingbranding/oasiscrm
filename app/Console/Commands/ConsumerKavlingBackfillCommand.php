<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\ConsumerKavlingBackfillService;
use Illuminate\Console\Command;

class ConsumerKavlingBackfillCommand extends Command
{
    protected $signature = 'consumer-kavling:backfill {mode=preview : preview atau execute} {--branch= : ID Cabang}';

    protected $description = 'Backfill kavling status dari histori konsumen';

    public function handle(ConsumerKavlingBackfillService $backfillService): int
    {
        $branchId = $this->option('branch');
        if (! $branchId) {
            $this->error('Option --branch wajib diisi.');

            return 1;
        }

        $branch = Branch::find($branchId);
        if (! $branch) {
            $this->error("Branch dengan ID {$branchId} tidak ditemukan.");

            return 1;
        }

        $mode = $this->argument('mode');
        if ($mode === 'execute') {
            $this->warn('Mode execute belum tersedia pada tugas migrasi ini. Jalankan preview terlebih dahulu.');

            return 1;
        }

        $results = $backfillService->preview($branch);

        $this->info("Preview backfill untuk Cabang: {$branch->name} (ID: {$branch->id})");
        $this->table(
            ['Metrik', 'Jumlah'],
            collect($results)->map(fn ($val, $key) => [$key, $val])->values()->toArray()
        );

        return 0;
    }
}
