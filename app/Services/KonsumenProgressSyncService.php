<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\KonsumenProgressSheetRow;
use App\Models\KonsumenProgressSyncStatus;
use Illuminate\Support\Facades\DB;
use Throwable;

class KonsumenProgressSyncService
{
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

    public function __construct(private GoogleSheetsApiService $googleSheets)
    {
    }

    public function syncBranch(Branch $branch): array
    {
        $status = KonsumenProgressSyncStatus::updateOrCreate(
            ['branch_id' => $branch->id],
            [
                'status' => 'running',
                'message' => null,
                'started_at' => now(),
            ]
        );

        if (!$branch->sheet_id) {
            $message = 'Branch belum memiliki sheet_id.';
            $status->update([
                'status' => 'failed',
                'message' => $message,
                'finished_at' => now(),
            ]);

            return ['ok' => false, 'branch' => $branch->name, 'message' => $message, 'summary' => []];
        }

        try {
            $ranges = array_map(fn ($sheet) => $this->quoteSheetName($sheet) . '!A:Z', self::SHEETS);
            $sheetRows = $this->googleSheets->batchGet($branch->sheet_id, $ranges);
            $syncedAt = now();
            $summary = [];

            DB::transaction(function () use ($branch, $sheetRows, $syncedAt, &$summary) {
                KonsumenProgressSheetRow::where('branch_id', $branch->id)->delete();

                foreach (self::SHEETS as $sheetName) {
                    $rows = $sheetRows[$sheetName] ?? [];
                    $summary[$sheetName] = count($rows);

                    foreach (array_chunk($this->buildInsertRows($branch, $sheetName, $rows, $syncedAt), 500) as $chunk) {
                        if (!empty($chunk)) {
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
            ]);

            return ['ok' => true, 'branch' => $branch->name, 'message' => 'OK', 'summary' => $summary];
        } catch (Throwable $e) {
            $status->update([
                'status' => 'failed',
                'message' => $e->getMessage(),
                'finished_at' => now(),
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

    private function buildInsertRows(Branch $branch, string $sheetName, array $rows, $syncedAt): array
    {
        return array_map(function (array $row, int $index) use ($branch, $sheetName, $syncedAt) {
            return [
                'branch_id' => $branch->id,
                'sheet_id' => $branch->sheet_id,
                'sheet_name' => $sheetName,
                'row_hash' => hash('sha256', $sheetName . '|' . $index . '|' . json_encode($row)),
                'row_data' => json_encode($row),
                'synced_at' => $syncedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $rows, array_keys($rows));
    }

    private function quoteSheetName(string $sheetName): string
    {
        return "'" . str_replace("'", "''", $sheetName) . "'";
    }
}
