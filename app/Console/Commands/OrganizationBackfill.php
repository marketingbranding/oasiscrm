<?php

namespace App\Console\Commands;

use App\Services\OrganizationBackfillService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('organization:backfill {--dry-run : Hanya menghitung proposal tanpa menulis data}')]
#[Description('Memeriksa dan melakukan backfill idempoten dari struktur organisasi legacy.')]
class OrganizationBackfill extends Command
{
    public function handle(OrganizationBackfillService $backfill): int
    {
        $report = $backfill->run((bool) $this->option('dry-run'));
        $this->table(
            ['Metrik', 'Nilai'],
            collect($report)->except('dry_run', 'details')->map(fn ($value, $key) => [$key, $value])->values()->all(),
        );

        if ($report['details'] !== []) {
            $this->warn('Detail konflik/anomali:');
            $this->line(json_encode($report['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
