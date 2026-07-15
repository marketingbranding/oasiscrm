<?php

namespace App\Console\Commands;

use App\Services\DanaTalanganGoogleService;
use Illuminate\Console\Command;

class SyncDanaTalangan extends Command
{
    protected $signature = 'dana-talangan:sync {--dry-run : Preview changes without writing data}';

    protected $description = 'Synchronize Dana Talangan with its monthly Google Sheets tabs';

    public function handle(DanaTalanganGoogleService $service): int
    {
        $result = $service->sync(null, (bool) $this->option('dry-run'));
        if (! $result['ok']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $summary = $result['summary'];
        $this->table(['Matched', 'Imported', 'Updated', 'Pushed', 'Deleted', 'Legacy', 'Warnings'], [[
            $summary['matched'],
            $summary['imported'],
            $summary['updated'],
            $summary['pushed'],
            $summary['deleted'],
            $summary['legacy_local'],
            count($summary['warnings']),
        ]]);
        foreach ($summary['warnings'] as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
