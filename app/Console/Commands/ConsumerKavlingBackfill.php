<?php

namespace App\Console\Commands;

use App\Services\ConsumerKavlingBackfillService;
use Illuminate\Console\Command;

class ConsumerKavlingBackfill extends Command
{
    protected $signature = 'consumer-kavling:backfill {--branch= : Filter by branch ID} {--project= : Filter by project ID} {--execute : Perform writes (default: preview only)} {--format= : Output format: table or json}';

    protected $description = 'Preview or execute backfill of existing ConsumerApplication.kavling_id into consumer_kavling_assignments';

    public function handle(ConsumerKavlingBackfillService $service): int
    {
        $branchId = $this->option('branch') ? (int) $this->option('branch') : null;
        $projectId = $this->option('project') ? (int) $this->option('project') : null;
        $execute = $this->option('execute');
        $format = $this->option('format') ?? 'table';

        if ($execute) {
            $this->warn('MODE: EXECUTE - Database writes will be performed.');
            $this->warn('Running in transaction with row-level locking.');
            $this->newLine();
        } else {
            $this->info('MODE: PREVIEW - No database writes will be performed.');
            $this->newLine();
        }

        $candidates = $service->preview($branchId, $projectId);

        $counts = [
            'TOTAL_CANDIDATES' => $candidates->count(),
            'READY_RESERVED' => $candidates->where('classification', 'READY_RESERVED')->count(),
            'READY_SOLD' => $candidates->where('classification', 'READY_SOLD')->count(),
            'ALREADY_BACKFILLED' => $candidates->where('classification', 'ALREADY_BACKFILLED')->count(),
            'CONFLICT' => $candidates->where('classification', 'CONFLICT')->count(),
            'SKIPPED' => $candidates->where('classification', 'SKIPPED')->count(),
        ];

        if ($format === 'json') {
            $this->line(json_encode([
                'mode' => $execute ? 'execute' : 'preview',
                'summary' => $counts,
                'rows' => $candidates->values()->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['METRIC', 'COUNT'],
                collect($counts)->map(fn ($count, $key) => [$key, $count])->values()->all(),
            );

            if ($candidates->isNotEmpty()) {
                $this->newLine();
                $this->info('Row Details:');
                $this->table(
                    ['APP_ID', 'BRANCH', 'PROJECT', 'KAVLING', 'STATUS', 'CLASS', 'REASON'],
                    $candidates->map(fn (array $row) => [
                        $row['application_id'],
                        $row['branch_name'],
                        $row['project_name'],
                        $row['kavling_code'],
                        $row['intended_status'],
                        $row['classification'],
                        $row['reason'],
                    ])->values()->all(),
                );
            }
        }

        if ($execute) {
            $result = $service->execute($branchId, $projectId);

            if ($format === 'json') {
                $this->line(json_encode(['execution' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->newLine();
                $this->info("Execution complete: {$result['created']} created, {$result['skipped']} skipped.");
            }
        }

        return self::SUCCESS;
    }
}
