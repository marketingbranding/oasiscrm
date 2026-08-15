<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\SalesAgendaEvidenceArchiveService;
use Illuminate\Console\Command;

class ArchiveSalesAgendaEvidence extends Command
{
    protected $signature = 'agenda-evidence:archive-weekly {--week=}';

    protected $description = 'Arsipkan bukti foto agenda Sales per cabang dan minggu';

    public function handle(SalesAgendaEvidenceArchiveService $service): int
    {
        $week = $this->option('week') ?: now()->subWeek()->startOfWeek()->toDateString();
        $failed = false;
        Branch::query()->each(function (Branch $branch) use ($service, $week, &$failed) {
            if ($service->build($branch, $week)->status === 'failed') {
                $failed = true;
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
