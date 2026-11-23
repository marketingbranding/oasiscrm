<?php

namespace App\Console\Commands;

use App\Exports\BranchParityReportExport;
use App\Models\Branch;
use App\Services\BranchParityReportService;
use Illuminate\Console\Command;

class BranchParity extends Command
{
    protected $signature = 'consumer:parity-report {branch : ID atau kode cabang} {--xlsx : Ekspor laporan XLSX}';

    protected $description = 'Membandingkan cermin sheet dan data OASIS per cabang tanpa mengubah data.';

    public function handle(BranchParityReportService $service): int
    {
        $branch = Branch::query()->whereKey($this->argument('branch'))->orWhere('code', $this->argument('branch'))->first();
        if (! $branch) {
            $this->error('Cabang tidak ditemukan.');

            return self::FAILURE;
        }
        $report = $service->report($branch);
        if ($this->option('xlsx')) {
            $response = BranchParityReportExport::toBrowser($report);
            $this->info($response->getFile()->getPathname());

            return self::SUCCESS;
        }
        $this->table(['Metrik', 'Nilai'], collect($report->metrics)->map(fn (mixed $value, string $key): array => [$key, is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value])->values()->all());
        $this->info('Status paritas: '.$report->status.'. Tidak ada data operasional yang diubah.');

        return self::SUCCESS;
    }
}
