<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\DatabaseSheetSyncService;
use App\Services\GoogleSheetsApiService;
use Illuminate\Console\Command;

class SheetCleanupMeta extends Command
{
    protected $signature = 'sheet:cleanup-meta
        {--branch= : Only clean this branch ID}
        {--dry-run : Show what would be deleted without changing anything}';

    protected $description = 'Remove oasis_sync_id, oasis_deleted_at, oasis_deleted_by columns from all sheet tabs';

    public function handle(GoogleSheetsApiService $googleSheets): int
    {
        $metaColumns = DatabaseSheetSyncService::META_COLUMNS;

        $query = Branch::whereNotNull('sheet_id')->where('sheet_id', '!=', '');
        if ($branchId = $this->option('branch')) {
            $query->where('id', $branchId);
        }

        $branches = $query->get();
        if ($branches->isEmpty()) {
            $this->warn('No branches with sheet_id found.');
            return 0;
        }

        $dryRun = $this->option('dry-run');
        $totalDeleted = 0;

        foreach ($branches as $branch) {
            $this->line(sprintf('Branch: %s (sheet: %s)', $branch->name, $branch->sheet_id));

            $sheetIds = $googleSheets->sheetIds($branch->sheet_id);
            $sheetTitles = $googleSheets->sheetTitles($branch->sheet_id);

            foreach ($sheetTitles as $title) {
                $sheetId = $sheetIds[$title] ?? null;
                if ($sheetId === null) continue;

                $range = $googleSheets->quoteSheetName($title) . '!A1:ZZ1';
                $result = $googleSheets->batchGetRaw($branch->sheet_id, [$range]);

                $headers = $result[$title][0] ?? [];
                if (empty($headers)) continue;

                $columnIndices = [];
                foreach ($headers as $i => $header) {
                    $normalized = trim((string) $header);
                    if (in_array($normalized, $metaColumns, true)) {
                        $columnIndices[] = $i;
                    }
                }

                if (empty($columnIndices)) continue;

                $this->info(sprintf('  Sheet "%s": %d meta column(s) at indices [%s]',
                    $title,
                    count($columnIndices),
                    implode(', ', $columnIndices)
                ));

                if ($dryRun) continue;

                rsort($columnIndices);
                foreach ($columnIndices as $idx) {
                    $googleSheets->deleteColumns($branch->sheet_id, $sheetId, $idx, $idx + 1);
                    $totalDeleted++;
                }
            }
        }

        if ($dryRun) {
            $this->info('DRY-RUN complete. Run without --dry-run to apply.');
        } else {
            $this->info(sprintf('Done. Deleted %d column(s) across %d branch(es).', $totalDeleted, $branches->count()));
            $this->warn('Run a sync after cleanup to refresh local cache.');
        }

        return 0;
    }
}
