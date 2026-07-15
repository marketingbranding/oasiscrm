<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\DatabaseSheetRecord;
use Illuminate\Support\Str;
use Throwable;

class DatabaseSheetWriteService
{
    public function __construct(private GoogleSheetsApiService $googleSheets, private DatabaseSheetSyncService $syncService) {}

    public function updateRecord(DatabaseSheetRecord $record, array $input): bool
    {
        $headers = $record->headers;
        $formulaColumns = $record->formula_columns ?? [];
        $rowData = $record->row_data;
        $editable = $this->editableHeaders($headers, $formulaColumns);
        $changed = [];

        foreach ($editable as $header) {
            if (array_key_exists($header, $input)) {
                $value = trim((string) $input[$header]);
                if (($rowData[$header] ?? '') !== $value) {
                    $rowData[$header] = $value;
                    $changed[$header] = $value;
                }
            }
        }

        if (empty($rowData['oasis_sync_id'])) {
            $rowData['oasis_sync_id'] = (string) Str::uuid();
        }

        try {
            foreach ($changed as $header => $value) {
                $this->updateCell($record->branch, $record->sheet_name, $record->row_number, $headers, $header, $value);
            }

            $record->update([
                'oasis_sync_id' => $record->oasis_sync_id ?: $rowData['oasis_sync_id'],
                'row_data' => $rowData,
                'sync_status' => 'synced',
                'last_sync_error' => null,
                'last_synced_at' => now(),
            ]);

            return true;
        } catch (Throwable $e) {
            $record->update([
                'row_data' => $rowData,
                'sync_status' => 'failed',
                'last_sync_error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function createRecord(Branch $branch, string $sheetName, array $input): bool
    {
        $template = DatabaseSheetRecord::where('branch_id', $branch->id)
            ->where('sheet_name', $sheetName)
            ->orderByDesc('row_number')
            ->first();

        if (! $template) {
            return false;
        }

        $headers = $template->headers;
        $formulaColumns = $template->formula_columns ?? [];
        $columnMetadata = $template->column_metadata ?? [];
        $rowNumber = ((int) DatabaseSheetRecord::where('branch_id', $branch->id)->where('sheet_name', $sheetName)->max('row_number')) + 1;
        $rowData = array_fill_keys($headers, '');
        $syncId = (string) Str::uuid();

        foreach ($this->editableHeaders($headers, $formulaColumns) as $header) {
            if (array_key_exists($header, $input)) {
                $rowData[$header] = trim((string) $input[$header]);
            }
        }

        $rowData['oasis_sync_id'] = $syncId;

        try {
            $sheetIds = $this->googleSheets->sheetIds($branch->sheet_id);
            if (isset($sheetIds[$sheetName])) {
                $this->googleSheets->copyRowFormat($branch->sheet_id, $sheetIds[$sheetName], $template->row_number, $rowNumber);
                $this->googleSheets->copyRowFormulas($branch->sheet_id, $sheetIds[$sheetName], $template->row_number, $rowNumber);
            }

            foreach ($this->editableHeaders($headers, $formulaColumns) as $header) {
                $this->updateCell($branch, $sheetName, $rowNumber, $headers, $header, $rowData[$header] ?? '');
            }

            DatabaseSheetRecord::create([
                'branch_id' => $branch->id,
                'sheet_id' => $branch->sheet_id,
                'sheet_name' => $sheetName,
                'row_number' => $rowNumber,
                'oasis_sync_id' => $syncId,
                'headers' => $headers,
                'row_data' => $rowData,
                'formula_columns' => $formulaColumns,
                'column_metadata' => $columnMetadata,
                'sync_status' => 'synced',
                'last_synced_at' => now(),
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function softDelete(DatabaseSheetRecord $record, int $userId): bool
    {
        $deletedAt = now()->toDateTimeString();
        $rowData = $record->row_data;
        $rowData['oasis_deleted_at'] = $deletedAt;
        $rowData['oasis_deleted_by'] = (string) $userId;

        try {
            $record->update([
                'row_data' => $rowData,
                'oasis_deleted_at' => $deletedAt,
                'oasis_deleted_by' => $userId,
                'sync_status' => 'synced',
                'last_sync_error' => null,
                'last_synced_at' => now(),
            ]);

            return true;
        } catch (Throwable $e) {
            $record->update(['sync_status' => 'failed', 'last_sync_error' => $e->getMessage()]);

            return false;
        }
    }

    public function editableHeaders(array $headers, array $formulaColumns): array
    {
        return array_values(array_filter($headers, fn ($header) => ! in_array($header, $formulaColumns, true)
            && ! in_array($header, ['oasis_sync_id', 'oasis_deleted_at', 'oasis_deleted_by'], true)));
    }

    private function updateCell(Branch $branch, string $sheetName, int $rowNumber, array $headers, string $header, string $value): void
    {
        $index = array_search($header, $headers, true);
        if ($index === false) {
            return;
        }

        $column = $this->syncService->columnLetter($index + 1);
        $range = $this->googleSheets->quoteSheetName($sheetName).'!'.$column.$rowNumber;
        $this->googleSheets->updateRange($branch->sheet_id, $range, [[$value]]);
    }
}
