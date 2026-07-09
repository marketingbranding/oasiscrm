<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\DatabaseSheetRecord;
use App\Models\DatabaseSheetSyncStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DatabaseSheetSyncService
{
    public const META_COLUMNS = ['oasis_sync_id', 'oasis_deleted_at', 'oasis_deleted_by'];

    public function __construct(private GoogleSheetsApiService $googleSheets)
    {
    }

    public function syncBranch(Branch $branch): array
    {
        $status = DatabaseSheetSyncStatus::updateOrCreate(
            ['branch_id' => $branch->id],
            ['status' => 'running', 'message' => null, 'started_at' => now()]
        );

        if (!$branch->sheet_id) {
            return $this->fail($status, $branch, 'Branch belum memiliki sheet_id.');
        }

        try {
            $sheetNames = $this->googleSheets->sheetTitles($branch->sheet_id);
            $ranges = array_map(fn ($sheet) => $this->googleSheets->quoteSheetName($sheet) . '!A:ZZ', $sheetNames);
            $valuesBySheet = $this->googleSheets->batchGetRaw($branch->sheet_id, $ranges, 'FORMATTED_VALUE');
            $formulasBySheet = $this->googleSheets->batchGetRaw($branch->sheet_id, $ranges, 'FORMULA');
            $syncedAt = now();
            $summary = [];

            DB::transaction(function () use ($branch, $sheetNames, $valuesBySheet, $formulasBySheet, $syncedAt, &$summary) {
                DatabaseSheetRecord::where('branch_id', $branch->id)->delete();

                foreach ($sheetNames as $sheetName) {
                    $values = $valuesBySheet[$sheetName] ?? [];
                    $formulas = $formulasBySheet[$sheetName] ?? [];
                    if (empty($values)) {
                        $summary[$sheetName] = 0;
                        continue;
                    }

                    $headers = $this->normalizedHeaders($values[0] ?? []);
                    $formulaColumns = $this->formulaColumns($headers, $formulas);
                    $insertRows = $this->buildRecords($branch, $sheetName, $headers, $values, $formulaColumns, $syncedAt);
                    $summary[$sheetName] = count($insertRows);

                    foreach (array_chunk($insertRows, 500) as $chunk) {
                        if (!empty($chunk)) {
                            DatabaseSheetRecord::insert($chunk);
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
            return $this->fail($status, $branch, $e->getMessage());
        }
    }

    private function fail(DatabaseSheetSyncStatus $status, Branch $branch, string $message): array
    {
        $truncated = mb_strlen($message) > 5000
            ? mb_substr($message, 0, 5000) . '...'
            : $message;

        $status->update([
            'status' => 'failed',
            'message' => $truncated,
            'finished_at' => now(),
        ]);

        return ['ok' => false, 'branch' => $branch->name, 'message' => $truncated, 'summary' => []];
    }

    private function normalizedHeaders(array $headers): array
    {
        $result = [];
        $counts = [];
        foreach ($headers as $index => $header) {
            $header = trim((string) $header);
            if ($header === '') {
                $header = 'kolom_' . ($index + 1);
            }
            $counts[$header] = ($counts[$header] ?? 0) + 1;
            $result[] = $counts[$header] > 1 ? $header . '_' . $counts[$header] : $header;
        }

        return $result;
    }

    private function formulaColumns(array $headers, array $formulaRows): array
    {
        $formulaColumns = [];
        foreach (array_slice($formulaRows, 1) as $row) {
            foreach ($headers as $index => $header) {
                if (in_array($header, self::META_COLUMNS, true)) continue;
                $value = (string) ($row[$index] ?? '');
                if (str_starts_with($value, '=')) {
                    $formulaColumns[] = $header;
                }
            }
        }

        return array_values(array_unique($formulaColumns));
    }

    private function buildRecords(Branch $branch, string $sheetName, array $headers, array $values, array $formulaColumns, $syncedAt): array
    {
        $records = [];
        foreach (array_slice($values, 1) as $offset => $cells) {
            $rowData = [];
            foreach ($headers as $index => $header) {
                $rowData[$header] = trim((string) ($cells[$index] ?? ''));
            }

            if (count(array_filter($rowData, fn ($value) => $value !== '')) === 0) continue;

            $syncId = ($rowData['oasis_sync_id'] ?? '') ?: (string) Str::uuid();
            $deletedAt = $this->validDeletedAt($rowData['oasis_deleted_at'] ?? '');

            $records[] = [
                'branch_id' => $branch->id,
                'sheet_id' => $branch->sheet_id,
                'sheet_name' => $sheetName,
                'row_number' => $offset + 2,
                'oasis_sync_id' => $syncId,
                'headers' => json_encode($headers),
                'row_data' => json_encode($rowData),
                'formula_columns' => json_encode($formulaColumns),
                'sync_status' => 'synced',
                'last_synced_at' => $syncedAt,
                'oasis_deleted_at' => $deletedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $records;
    }

    public function columnLetter(int $columnNumber): string
    {
        $letter = '';
        while ($columnNumber > 0) {
            $columnNumber--;
            $letter = chr(65 + ($columnNumber % 26)) . $letter;
            $columnNumber = intdiv($columnNumber, 26);
        }

        return $letter;
    }

    private function validDeletedAt(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return now()->parse($value)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }
}
