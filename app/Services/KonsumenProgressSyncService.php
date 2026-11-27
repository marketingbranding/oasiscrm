<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\KonsumenProgressSheetRow;
use App\Models\KonsumenProgressSyncStatus;
use Illuminate\Support\Facades\DB;
use Throwable;

class KonsumenProgressSyncService
{
    private SyncLockService $locks;

    public const STAGES = [
        'bi_checking' => 'BI Checking',
        'PSJB' => 'PSJB',
        'pemberkasan' => 'Pemberkasan',
        'proses_bank' => 'Proses Bank',
        'ppjb_dev' => 'PPJB Dev',
        'akad' => 'Akad',
        'bast' => 'BAST',
    ];

    public const SHEETS = [
        'data_konsumen',
        'bi_checking',
        'PSJB',
        'pemberkasan',
        'proses_bank',
        'ppjb_dev',
        'akad',
        'bast',
    ];

    public function __construct(private GoogleSheetsApiService $googleSheets, ?SyncLockService $locks = null, private ?BranchMigrationStateService $migrationStates = null, private ?SheetProjectOwnershipService $ownership = null)
    {
        $this->locks = $locks ?? app(SyncLockService::class);
        $this->migrationStates ??= app(BranchMigrationStateService::class);
        $this->ownership ??= app(SheetProjectOwnershipService::class);
    }

    public function syncBranch(Branch $branch, ?int $initiatedBy = null, bool $acquireLock = true): array
    {
        if (! $this->migrationStates->allowsSheetSync($branch)) {
            $this->migrationStates->logSkip($branch, 'konsumen_progress');

            return ['ok' => true, 'status' => 'skipped', 'branch' => $branch->name, 'message' => 'Cabang berstatus oasis_penuh.', 'reason' => 'oasis_penuh', 'summary' => []];
        }

        return $acquireLock
            ? $this->locks->run('konsumen-progress:branch:'.$branch->id, fn () => $this->performSync($branch, $initiatedBy))
            : $this->performSync($branch, $initiatedBy);
    }

    private function performSync(Branch $branch, ?int $initiatedBy): array
    {
        $started = now();
        $status = KonsumenProgressSyncStatus::updateOrCreate(
            ['branch_id' => $branch->id],
            [
                'status' => 'running',
                'message' => null,
                'summary' => null,
                'started_at' => $started,
                'finished_at' => null,
                'duration_ms' => null,
                'initiated_by' => $initiatedBy,
            ]
        );

        if (! $branch->sheet_id) {
            $message = 'Branch belum memiliki sheet_id.';
            $status->update([
                'status' => 'failed',
                'message' => $message,
                'finished_at' => now(),
            ]);

            return ['ok' => false, 'branch' => $branch->name, 'message' => $message, 'summary' => []];
        }

        try {
            $ranges = array_map(fn ($sheet) => $this->quoteSheetName($sheet).'!A:Z', self::SHEETS);
            $sheetRows = $this->googleSheets->batchGetWithRowNumbers($branch->sheet_id, $ranges);
            foreach (self::SHEETS as $sheetName) {
                if (! array_key_exists($sheetName, $sheetRows)) {
                    throw new \RuntimeException('Respons Google Sheets tidak lengkap. Cache lokal tidak diubah.');
                }
            }
            $syncedAt = now();
            $summary = [];

            DB::transaction(function () use ($branch, $sheetRows, $syncedAt, &$summary) {
                KonsumenProgressSheetRow::where('branch_id', $branch->id)->delete();

                foreach (self::SHEETS as $sheetName) {
                    $sheet = $sheetRows[$sheetName] ?? ['rows' => [], 'row_indexes' => []];
                    $filtered = $this->ownership->filterRows($branch, $sheet['rows']);
                    $summary[$sheetName] = count($filtered['rows']);
                    $summary[$sheetName.'_foreign_project_rows'] = $filtered['foreign_project_rows'];
                    $summary[$sheetName.'_unmapped_project_rows'] = $filtered['unmapped_project_rows'];

                    foreach (array_chunk($this->buildInsertRows($branch, $sheetName, $filtered['all_rows'], $sheet['row_indexes'], $filtered['statuses'], $syncedAt), 500) as $chunk) {
                        if (! empty($chunk)) {
                            KonsumenProgressSheetRow::insert($chunk);
                        }
                    }
                }
            });

            $status->update([
                'status' => 'success',
                'message' => null,
                'summary' => $summary,
                'finished_at' => $syncedAt,
                'last_successful_at' => $syncedAt,
                'duration_ms' => $started->diffInMilliseconds($syncedAt),
            ]);

            return ['ok' => true, 'branch' => $branch->name, 'message' => 'OK', 'summary' => $summary];
        } catch (Throwable $e) {
            $status->update([
                'status' => 'failed',
                'message' => $e->getMessage(),
                'finished_at' => now(),
                'duration_ms' => $started->diffInMilliseconds(now()),
            ]);

            return ['ok' => false, 'branch' => $branch->name, 'message' => $e->getMessage(), 'summary' => []];
        }
    }

    public function syncAll(?int $branchId = null): array
    {
        $query = Branch::where('is_active', true)->whereNotNull('sheet_id');
        if ($branchId) {
            $query->whereKey($branchId);
        }

        return $query->get()->map(fn (Branch $branch) => $this->syncBranch($branch))->all();
    }

    private function buildInsertRows(Branch $branch, string $sheetName, array $rows, array $rowIndexes, array $statuses, $syncedAt): array
    {
        return array_map(function (array $row, int $index) use ($branch, $sheetName, $rowIndexes, $statuses, $syncedAt) {
            return [
                'branch_id' => $branch->id,
                'sheet_id' => $branch->sheet_id,
                'sheet_name' => $sheetName,
                'row_hash' => hash('sha256', $sheetName.'|'.($rowIndexes[$index] ?? $index)),
                'ownership_status' => $statuses[$index] ?? 'owned',
                'row_data' => json_encode($row),
                'synced_at' => $syncedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $rows, array_keys($rows));
    }

    private function quoteSheetName(string $sheetName): string
    {
        return "'".str_replace("'", "''", $sheetName)."'";
    }
}
