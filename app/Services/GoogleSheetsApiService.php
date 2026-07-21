<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Google\Service\Sheets\CopyPasteRequest;
use Google\Service\Sheets\GridRange;
use Google\Service\Sheets\Request;
use Google\Service\Sheets\ValueRange;
use GuzzleHttp\Client as GuzzleClient;
use RuntimeException;

class GoogleSheetsApiService
{
    private Sheets $sheets;

    public function __construct()
    {
        $credentialsPath = config('services.google_sheets.credentials_path');
        if (! $credentialsPath || ! file_exists($credentialsPath)) {
            throw new RuntimeException('Google Sheets credentials file tidak ditemukan: '.$credentialsPath);
        }

        $client = new GoogleClient;
        $client->setApplicationName('Oasis CRM');
        $client->setAuthConfig($credentialsPath);
        $client->setScopes([Sheets::SPREADSHEETS]);

        if (! config('services.google_sheets.verify_ssl')) {
            $client->setHttpClient(new GuzzleClient(['verify' => false]));
        }

        $this->sheets = new Sheets($client);
    }

    public function batchGet(string $spreadsheetId, array $ranges): array
    {
        $response = $this->sheets->spreadsheets_values->batchGet($spreadsheetId, [
            'ranges' => $ranges,
            'majorDimension' => 'ROWS',
        ]);

        $result = [];
        foreach ($response->getValueRanges() as $valueRange) {
            $range = $valueRange->getRange();
            $sheetName = $this->sheetNameFromRange($range);
            $result[$sheetName] = $this->valuesToRows($valueRange->getValues() ?? []);
        }

        return $result;
    }

    public function sheetTitles(string $spreadsheetId): array
    {
        $spreadsheet = $this->sheets->spreadsheets->get($spreadsheetId, [
            'fields' => 'sheets.properties.title',
        ]);

        return array_map(
            fn ($sheet) => $sheet->getProperties()->getTitle(),
            $spreadsheet->getSheets() ?? []
        );
    }

    public function sheetIds(string $spreadsheetId): array
    {
        $spreadsheet = $this->sheets->spreadsheets->get($spreadsheetId, [
            'fields' => 'sheets.properties(sheetId,title)',
        ]);

        $ids = [];
        foreach ($spreadsheet->getSheets() ?? [] as $sheet) {
            $properties = $sheet->getProperties();
            $ids[$properties->getTitle()] = $properties->getSheetId();
        }

        return $ids;
    }

    public function batchGetRaw(string $spreadsheetId, array $ranges, string $valueRenderOption = 'FORMATTED_VALUE'): array
    {
        $response = $this->sheets->spreadsheets_values->batchGet($spreadsheetId, [
            'ranges' => $ranges,
            'majorDimension' => 'ROWS',
            'valueRenderOption' => $valueRenderOption,
        ]);

        $result = [];
        foreach ($response->getValueRanges() as $valueRange) {
            $result[$this->sheetNameFromRange($valueRange->getRange())] = $valueRange->getValues() ?? [];
        }

        return $result;
    }

    public function columnMetadata(string $spreadsheetId, array $sheetNames): array
    {
        if (empty($sheetNames)) {
            return [];
        }

        $ranges = array_map(fn ($sheet) => $this->quoteSheetName($sheet).'!2:11', $sheetNames);
        $spreadsheet = $this->sheets->spreadsheets->get($spreadsheetId, [
            'ranges' => $ranges,
            'includeGridData' => true,
            'fields' => 'sheets(properties(title),data(startColumn,rowData(values(dataValidation,effectiveFormat(numberFormat)))))',
        ]);

        $metadata = [];
        $rangeReferences = [];

        foreach ($spreadsheet->getSheets() ?? [] as $sheet) {
            $sheetName = $sheet->getProperties()->getTitle();
            foreach ($sheet->getData() ?? [] as $gridData) {
                $startColumn = (int) ($gridData->getStartColumn() ?? 0);
                foreach ($gridData->getRowData() ?? [] as $rowData) {
                    foreach ($rowData->getValues() ?? [] as $offset => $cell) {
                        $columnIndex = $startColumn + $offset;
                        $cellMetadata = $this->cellMetadata($cell);
                        if (! $cellMetadata) {
                            continue;
                        }

                        $existing = $metadata[$sheetName][$columnIndex] ?? ['type' => 'text'];
                        if (($existing['type'] ?? 'text') === 'text' || $cellMetadata['type'] !== 'text') {
                            $metadata[$sheetName][$columnIndex] = array_replace($existing, $cellMetadata);
                        }

                        if (! empty($cellMetadata['source_range'])) {
                            $rangeReferences[$cellMetadata['source_range']] = true;
                        }
                    }
                }
            }
        }

        $rangeValues = $this->batchGetRanges($spreadsheetId, array_keys($rangeReferences));
        foreach ($metadata as &$sheetColumns) {
            foreach ($sheetColumns as &$column) {
                if (empty($column['source_range'])) {
                    continue;
                }

                $options = [];
                foreach ($rangeValues[$column['source_range']] ?? [] as $row) {
                    foreach ($row as $value) {
                        $value = trim((string) $value);
                        if ($value !== '') {
                            $options[$value] = $value;
                        }
                    }
                }
                $column['options'] = array_values($options);
                unset($column['source_range']);
            }
            unset($column);
        }
        unset($sheetColumns);

        return $metadata;
    }

    public function updateRange(string $spreadsheetId, string $range, array $values): void
    {
        $body = new ValueRange(['values' => $values]);
        $this->sheets->spreadsheets_values->update($spreadsheetId, $range, $body, [
            'valueInputOption' => 'USER_ENTERED',
        ]);
    }

    public function batchUpdateRanges(string $spreadsheetId, array $ranges): void
    {
        if (empty($ranges)) {
            return;
        }

        $data = collect($ranges)->map(fn (array $item) => new ValueRange([
            'range' => $item['range'],
            'values' => $item['values'],
        ]))->all();
        $this->sheets->spreadsheets_values->batchUpdate($spreadsheetId, new BatchUpdateValuesRequest([
            'valueInputOption' => 'USER_ENTERED',
            'data' => $data,
        ]));
    }

    public function appendRange(string $spreadsheetId, string $range, array $values): void
    {
        $body = new ValueRange(['values' => $values]);
        $this->sheets->spreadsheets_values->append($spreadsheetId, $range, $body, [
            'valueInputOption' => 'USER_ENTERED',
            'insertDataOption' => 'INSERT_ROWS',
        ]);
    }

    public function hideColumns(string $spreadsheetId, int $sheetId, int $startIndex, int $endIndex): void
    {
        $request = new Request([
            'updateDimensionProperties' => [
                'range' => [
                    'sheetId' => $sheetId,
                    'dimension' => 'COLUMNS',
                    'startIndex' => $startIndex,
                    'endIndex' => $endIndex,
                ],
                'properties' => ['hiddenByUser' => true],
                'fields' => 'hiddenByUser',
            ],
        ]);

        $this->sheets->spreadsheets->batchUpdate($spreadsheetId, new BatchUpdateSpreadsheetRequest([
            'requests' => [$request],
        ]));
    }

    public function copyRowFormulas(string $spreadsheetId, int $sheetId, int $sourceRowNumber, int $destinationRowNumber): void
    {
        if ($sourceRowNumber < 2 || $destinationRowNumber < 2) {
            return;
        }

        $request = new Request([
            'copyPaste' => new CopyPasteRequest([
                'source' => new GridRange([
                    'sheetId' => $sheetId,
                    'startRowIndex' => $sourceRowNumber - 1,
                    'endRowIndex' => $sourceRowNumber,
                ]),
                'destination' => new GridRange([
                    'sheetId' => $sheetId,
                    'startRowIndex' => $destinationRowNumber - 1,
                    'endRowIndex' => $destinationRowNumber,
                ]),
                'pasteType' => 'PASTE_FORMULA',
                'pasteOrientation' => 'NORMAL',
            ]),
        ]);

        $this->sheets->spreadsheets->batchUpdate($spreadsheetId, new BatchUpdateSpreadsheetRequest([
            'requests' => [$request],
        ]));
    }

    public function copyRowFormat(string $spreadsheetId, int $sheetId, int $sourceRowNumber, int $destinationRowNumber): void
    {
        if ($sourceRowNumber < 2 || $destinationRowNumber < 2) {
            return;
        }

        $request = new Request([
            'copyPaste' => new CopyPasteRequest([
                'source' => new GridRange([
                    'sheetId' => $sheetId,
                    'startRowIndex' => $sourceRowNumber - 1,
                    'endRowIndex' => $sourceRowNumber,
                ]),
                'destination' => new GridRange([
                    'sheetId' => $sheetId,
                    'startRowIndex' => $destinationRowNumber - 1,
                    'endRowIndex' => $destinationRowNumber,
                ]),
                'pasteType' => 'PASTE_FORMAT',
                'pasteOrientation' => 'NORMAL',
            ]),
        ]);

        $this->sheets->spreadsheets->batchUpdate($spreadsheetId, new BatchUpdateSpreadsheetRequest([
            'requests' => [$request],
        ]));
    }

    public function deleteColumns(string $spreadsheetId, int $sheetId, int $startIndex, int $endIndex): void
    {
        $request = new Request([
            'deleteDimension' => [
                'range' => [
                    'sheetId' => $sheetId,
                    'dimension' => 'COLUMNS',
                    'startIndex' => $startIndex,
                    'endIndex' => $endIndex,
                ],
            ],
        ]);

        $this->sheets->spreadsheets->batchUpdate($spreadsheetId, new BatchUpdateSpreadsheetRequest([
            'requests' => [$request],
        ]));
    }

    public function quoteSheetName(string $sheetName): string
    {
        return "'".str_replace("'", "''", $sheetName)."'";
    }

    private function sheetNameFromRange(string $range): string
    {
        $sheet = explode('!', $range, 2)[0];

        return trim($sheet, "'");
    }

    private function batchGetRanges(string $spreadsheetId, array $ranges): array
    {
        if (empty($ranges)) {
            return [];
        }

        $response = $this->sheets->spreadsheets_values->batchGet($spreadsheetId, [
            'ranges' => $ranges,
            'majorDimension' => 'ROWS',
            'valueRenderOption' => 'FORMATTED_VALUE',
        ]);

        $result = [];
        $valueRanges = $response->getValueRanges() ?? [];
        foreach ($ranges as $index => $range) {
            $result[$range] = ($valueRanges[$index] ?? null)?->getValues() ?? [];
        }

        return $result;
    }

    private function cellMetadata($cell): ?array
    {
        $validation = $cell->getDataValidation();
        $condition = $validation?->getCondition();
        $conditionType = $condition?->getType();
        $conditionValues = array_map(
            fn ($value) => trim((string) $value->getUserEnteredValue()),
            $condition?->getValues() ?? []
        );

        if ($conditionType === 'ONE_OF_LIST') {
            $options = array_values(array_unique(array_filter($conditionValues, fn ($value) => $value !== '')));

            return [
                'type' => 'select',
                'options' => $options,
                'strict' => (bool) $validation->getStrict(),
            ];
        }

        if ($conditionType === 'ONE_OF_RANGE' && ! empty($conditionValues[0])) {
            return [
                'type' => 'select',
                'options' => [],
                'strict' => (bool) $validation->getStrict(),
                'source_range' => ltrim($conditionValues[0], '='),
            ];
        }

        if ($conditionType === 'BOOLEAN') {
            return [
                'type' => 'checkbox',
                'checked_value' => $conditionValues[0] ?? 'TRUE',
                'unchecked_value' => $conditionValues[1] ?? 'FALSE',
            ];
        }

        if ($conditionType && str_starts_with($conditionType, 'DATE_')) {
            return ['type' => 'date'];
        }

        $numberType = $cell->getEffectiveFormat()?->getNumberFormat()?->getType();

        return match ($numberType) {
            'DATE' => ['type' => 'date'],
            'DATE_TIME' => ['type' => 'datetime-local'],
            'TIME' => ['type' => 'time'],
            default => null,
        };
    }

    private function valuesToRows(array $values): array
    {
        if (count($values) < 2) {
            return [];
        }

        $rawHeaders = array_shift($values);
        $headers = [];
        $counts = [];
        foreach ($rawHeaders as $header) {
            $header = trim((string) $header);
            if ($header === '') {
                $headers[] = null;

                continue;
            }
            if (! isset($counts[$header])) {
                $counts[$header] = 0;
            }
            $counts[$header]++;
            $headers[] = $counts[$header] > 1 ? $header.'_'.$counts[$header] : $header;
        }

        $rows = [];
        foreach ($values as $cells) {
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === null) {
                    continue;
                }
                $row[$header] = trim((string) ($cells[$index] ?? ''));
            }
            if (count(array_filter($row, fn ($value) => $value !== '')) > 0) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
