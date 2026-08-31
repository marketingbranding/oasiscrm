<?php

namespace App\Services;

use App\Exceptions\SalesLeadSpreadsheetContractException;
use App\ValueObjects\GoogleSheetsAppendResult;
use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Google\Service\Sheets\CopyPasteRequest;
use Google\Service\Sheets\GridRange;
use Google\Service\Sheets\Request;
use Google\Service\Sheets\SetDataValidationRequest;
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

        $client->setHttpClient(new GuzzleClient([
            'verify' => (bool) config('services.google_sheets.verify_ssl', true),
            'connect_timeout' => (float) config('services.google_sheets.connect_timeout', 10),
            'timeout' => (float) config('services.google_sheets.request_timeout', 60),
        ]));

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

    public function gridMetadata(string $spreadsheetId, string $sheetName, string $range = 'A:Q'): array
    {
        $spreadsheet = $this->sheets->spreadsheets->get($spreadsheetId, [
            'ranges' => [$this->quoteSheetName($sheetName).'!'.$range],
            'includeGridData' => true,
            'fields' => 'sheets(properties(sheetId,title),data(startRow,startColumn,rowData(values(userEnteredValue,dataValidation))))',
        ]);
        $sheet = collect($spreadsheet->getSheets() ?? [])->first(fn ($item) => $item->getProperties()->getTitle() === $sheetName);
        if ($sheet === null) {
            return [];
        }

        $formulas = [];
        $validations = [];
        foreach ($sheet->getData() ?? [] as $gridData) {
            $startRow = (int) ($gridData->getStartRow() ?? 0);
            $startColumn = (int) ($gridData->getStartColumn() ?? 0);
            foreach ($gridData->getRowData() ?? [] as $rowOffset => $rowData) {
                foreach ($rowData->getValues() ?? [] as $columnOffset => $cell) {
                    $row = $startRow + $rowOffset + 1;
                    $column = $startColumn + $columnOffset + 1;
                    $formula = (string) ($cell->getUserEnteredValue()?->getFormulaValue() ?? '');
                    if ($formula !== '') {
                        $formulas[] = ['row' => $row, 'column' => $column];
                    }
                    $validation = $cell->getDataValidation();
                    if ($validation !== null) {
                        $validations[] = [
                            'row' => $row,
                            'column' => $column,
                            'type' => $validation->getCondition()?->getType(),
                            'strict' => (bool) $validation->getStrict(),
                        ];
                    }
                }
            }
        }

        return [
            'sheet_id' => (int) $sheet->getProperties()->getSheetId(),
            'formulas' => $formulas,
            'validations' => $validations,
        ];
    }

    public function updateRange(string $spreadsheetId, string $range, array $values): void
    {
        $body = new ValueRange(['values' => $values]);
        $this->sheets->spreadsheets_values->update($spreadsheetId, $range, $body, [
            'valueInputOption' => 'USER_ENTERED',
        ]);
    }

    public function batchUpdateRanges(string $spreadsheetId, array $ranges, string $valueInputOption = 'USER_ENTERED'): void
    {
        if (empty($ranges)) {
            return;
        }

        $data = collect($ranges)->map(fn (array $item) => new ValueRange([
            'range' => $item['range'],
            'values' => $item['values'],
        ]))->all();
        $this->sheets->spreadsheets_values->batchUpdate($spreadsheetId, new BatchUpdateValuesRequest([
            'valueInputOption' => $valueInputOption,
            'data' => $data,
        ]));
    }

    public function readLeadRows(string $spreadsheetId): array
    {
        $raw = $this->batchGetRaw($spreadsheetId, [$this->quoteSheetName('lead').'!A:ZZ'], 'FORMATTED_VALUE');
        $values = $raw['lead'] ?? [];
        $headers = array_map(fn ($value) => trim((string) $value), $values[0] ?? []);
        $rows = [];
        foreach (array_slice($values, 1) as $offset => $cells) {
            $row = ['_row_number' => $offset + 2];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $row[$header] = trim((string) ($cells[$index] ?? ''));
                }
            }
            if (collect($row)->except('_row_number')->contains(fn ($value) => $value !== '')) {
                $rows[] = $row;
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    public function appendRange(string $spreadsheetId, string $range, array $values): void
    {
        $body = new ValueRange(['values' => $values]);
        $this->sheets->spreadsheets_values->append($spreadsheetId, $range, $body, [
            'valueInputOption' => 'USER_ENTERED',
            'insertDataOption' => 'INSERT_ROWS',
        ]);
    }

    public function appendRows(string $spreadsheetId, string $range, array $values): GoogleSheetsAppendResult
    {
        $body = new ValueRange(['values' => $values]);
        $response = $this->sheets->spreadsheets_values->append($spreadsheetId, $range, $body, [
            'valueInputOption' => 'USER_ENTERED',
            'insertDataOption' => 'INSERT_ROWS',
            'includeValuesInResponse' => true,
        ]);
        $updatedRange = (string) $response->getUpdates()?->getUpdatedRange();
        if (! preg_match('/![A-Z]+(\d+)(?::[A-Z]+\d+)?$/i', $updatedRange, $matches)) {
            throw new RuntimeException('Google Sheets tidak mengembalikan baris hasil append.');
        }

        return new GoogleSheetsAppendResult($updatedRange, (int) $matches[1]);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $metadataHeaders
     * @return list<string>
     */
    public function ensureTrailingMetadataColumns(
        string $spreadsheetId,
        string $sheetName,
        int $sheetId,
        array $headers,
        array $metadataHeaders,
    ): array {
        $present = array_values(array_intersect($headers, $metadataHeaders));
        if ($present !== []) {
            if ($present !== $metadataHeaders || array_slice($headers, -count($metadataHeaders)) !== $metadataHeaders) {
                throw SalesLeadSpreadsheetContractException::metadataUnsafe($sheetName);
            }

            return $headers;
        }

        $startColumn = count($headers) + 1;
        $endColumn = $startColumn + count($metadataHeaders) - 1;
        $range = $this->quoteSheetName($sheetName).'!'.$this->columnLetter($startColumn).'1:'.$this->columnLetter($endColumn).'1';
        $this->updateRange($spreadsheetId, $range, [$metadataHeaders]);

        $verified = $this->batchGetRaw(
            $spreadsheetId,
            [$this->quoteSheetName($sheetName).'!1:1'],
            'FORMATTED_VALUE',
        )[$sheetName][0] ?? [];
        $verified = array_map(fn ($header) => trim((string) $header), $verified);
        if (array_slice($verified, -count($metadataHeaders)) !== $metadataHeaders) {
            throw SalesLeadSpreadsheetContractException::metadataUnsafe($sheetName);
        }

        // Only columns created and re-read by this call are hidden.
        $this->hideColumns($spreadsheetId, $sheetId, $startColumn - 1, $endColumn);

        return $verified;
    }

    /** @param list<string> $headers */
    public function writeRowMetadata(string $spreadsheetId, string $sheetName, array $headers, int $rowNumber, ?string $syncId, ?string $deletedAt, ?int $deletedBy): void
    {
        $values = ['oasis_sync_id' => $syncId, 'oasis_deleted_at' => $deletedAt, 'oasis_deleted_by' => $deletedBy];
        $ranges = [];
        foreach ($values as $header => $value) {
            $index = array_search($header, $headers, true);
            if ($index !== false) {
                $column = $this->columnLetter($index + 1);
                $ranges[] = ['range' => $this->quoteSheetName($sheetName)."!{$column}{$rowNumber}", 'values' => [[$value]]];
            }
        }
        $this->batchUpdateRanges($spreadsheetId, $ranges, 'RAW');
    }

    public function findRowByHeaderValue(
        string $spreadsheetId,
        string $sheetName,
        array $headers,
        string $header,
        string $value,
    ): ?int {
        $index = array_search($header, $headers, true);
        if ($index === false) {
            return null;
        }

        $column = $this->columnLetter($index + 1);
        $rows = $this->batchGetRaw(
            $spreadsheetId,
            [$this->quoteSheetName($sheetName)."!{$column}2:{$column}"],
            'FORMATTED_VALUE',
        )[$sheetName] ?? [];
        foreach ($rows as $offset => $row) {
            if (hash_equals($value, trim((string) ($row[0] ?? '')))) {
                return $offset + 2;
            }
        }

        return null;
    }

    public function makeColumnValidationWarningOnly(string $spreadsheetId, string $sheetName, int $sheetId, int $columnIndex): void
    {
        $spreadsheet = $this->sheets->spreadsheets->get($spreadsheetId, [
            'ranges' => [$this->quoteSheetName($sheetName).'!'.$this->columnLetter($columnIndex + 1).'2'],
            'includeGridData' => true,
            'fields' => 'sheets.data.rowData.values.dataValidation',
        ]);
        $rule = $spreadsheet->getSheets()[0]?->getData()[0]?->getRowData()[0]?->getValues()[0]?->getDataValidation();
        if ($rule === null || ! in_array($rule->getCondition()?->getType(), ['ONE_OF_LIST', 'ONE_OF_RANGE'], true) || ! $rule->getStrict()) {
            return;
        }

        $rule->setStrict(false);
        $request = new Request([
            'setDataValidation' => new SetDataValidationRequest([
                'range' => new GridRange([
                    'sheetId' => $sheetId,
                    'startRowIndex' => 1,
                    'startColumnIndex' => $columnIndex,
                    'endColumnIndex' => $columnIndex + 1,
                ]),
                'rule' => $rule,
            ]),
        ]);
        $this->sheets->spreadsheets->batchUpdate($spreadsheetId, new BatchUpdateSpreadsheetRequest([
            'requests' => [$request],
        ]));
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

    private function columnLetter(int $column): string
    {
        $letter = '';
        while ($column > 0) {
            $column--;
            $letter = chr(65 + ($column % 26)).$letter;
            $column = intdiv($column, 26);
        }

        return $letter;
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
