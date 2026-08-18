<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\DatabaseSheetRecord;

class DatabaseSheetImportService
{
    public const MAX_ROWS = 200;

    public function __construct(private readonly DatabaseSheetWriteService $writeService) {}

    public function preview(Branch $branch, string $sheetName, string $raw): array
    {
        $analysis = $this->analyze($branch, $sheetName, $raw);

        return $analysis;
    }

    public function save(Branch $branch, string $sheetName, string $raw): array
    {
        $analysis = $this->analyze($branch, $sheetName, $raw);

        if (isset($analysis['error'])) {
            return ['saved' => 0, 'failed' => 0, 'total' => 0, 'message' => $analysis['error']];
        }

        $saved = 0;
        $failed = 0;

        foreach ($analysis['rows'] as $row) {
            if ($row['status'] !== 'VALID') {
                $failed++;

                continue;
            }

            if ($this->writeService->createRecord($branch, $sheetName, $row['values'])) {
                $saved++;
            } else {
                $failed++;
            }
        }

        return [
            'saved' => $saved,
            'failed' => $failed,
            'total' => $saved + $failed,
            'message' => "Berhasil disimpan: {$saved}\nGagal: {$failed}",
        ];
    }

    private function analyze(Branch $branch, string $sheetName, string $raw): array
    {
        $template = DatabaseSheetRecord::query()
            ->where('branch_id', $branch->id)
            ->where('sheet_name', $sheetName)
            ->orderByDesc('row_number')
            ->first();

        if (! $template) {
            return ['error' => 'Template sheet belum tersedia. Sinkronkan data terlebih dahulu.'];
        }

        $editable = $this->writeService->editableHeaders($template->headers, $template->formula_columns ?? []);

        $editableLookup = [];
        foreach ($editable as $header) {
            $editableLookup[$this->headerKey($header)] = $header;
        }

        $ignoredLookup = [];
        foreach (array_merge($template->formula_columns ?? [], DatabaseSheetSyncService::META_COLUMNS) as $header) {
            $ignoredLookup[$this->headerKey($header)] = true;
        }

        $parsed = $this->parse($raw);
        $unknownHeaders = [];
        foreach ($parsed['headers'] as $header) {
            if ($header === '') {
                continue;
            }
            $key = $this->headerKey($header);
            if (! isset($editableLookup[$key]) && ! isset($ignoredLookup[$key])) {
                $unknownHeaders[] = $header;
            }
        }

        if ($unknownHeaders !== []) {
            return ['error' => 'Kolom tidak dikenal: '.implode(', ', array_values(array_unique($unknownHeaders)))];
        }

        if (count($parsed['rows']) > self::MAX_ROWS) {
            return ['error' => 'Maksimal '.self::MAX_ROWS.' baris per import.'];
        }

        $rows = [];
        $validCount = 0;
        $invalidCount = 0;

        foreach ($parsed['rows'] as $index => $row) {
            $normalized = [];
            $errors = [];

            foreach ($row as $header => $value) {
                if ($header === '') {
                    continue;
                }
                $key = $this->headerKey($header);
                if (isset($ignoredLookup[$key])) {
                    continue;
                }
                $actual = $editableLookup[$key] ?? null;
                if ($actual === null) {
                    continue;
                }

                $metadata = $template->column_metadata[$actual] ?? [];
                [$value, $error] = $this->validateTypedValue($actual, trim((string) $value), $metadata);

                if ($error !== null) {
                    $errors[] = $error;
                }

                $normalized[$actual] = $value;
            }

            $status = $errors === [] ? 'VALID' : 'ERROR';
            if ($status === 'VALID') {
                $validCount++;
            } else {
                $invalidCount++;
            }

            $rows[] = [
                'line' => $index + 1,
                'values' => $normalized,
                'status' => $status,
                'errors' => $errors,
            ];
        }

        return [
            'sheet_name' => $sheetName,
            'headers' => $editable,
            'rows' => $rows,
            'valid_count' => $validCount,
            'invalid_count' => $invalidCount,
        ];
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, string>>}
     */
    private function parse(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = array_values(array_filter(
            array_map(fn (string $line) => rtrim($line, "\t "), explode("\n", $raw)),
            fn (string $line) => trim($line) !== ''
        ));

        if ($lines === []) {
            return ['headers' => [], 'rows' => []];
        }

        $headers = array_map(fn (string $header) => trim($header), explode("\t", $lines[0]));
        $rows = [];

        for ($i = 1; $i < count($lines); $i++) {
            $cells = explode("\t", $lines[$i]);
            $row = [];
            foreach ($headers as $column => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = trim((string) ($cells[$column] ?? ''));
            }

            if (count(array_filter($row, fn (string $value) => $value !== '')) > 0) {
                $rows[] = $row;
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function headerKey(string $header): string
    {
        $key = strtolower(trim($header));
        $key = str_replace('_', ' ', $key);

        return preg_replace('/\s+/', ' ', $key) ?? $key;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function validateTypedValue(string $header, string $value, array $metadata): array
    {
        if ($value === '') {
            return ['', null];
        }

        $type = $metadata['type'] ?? 'text';

        if ($type === 'select' && ! empty($metadata['strict']) && ! empty($metadata['options'])
            && ! in_array($value, $metadata['options'], true)) {
            return [$value, $header.' tidak valid'];
        }

        $normalized = match ($type) {
            'date' => $this->normalizeDate($value),
            'datetime-local' => $this->normalizeDateTime($value),
            'time' => $this->normalizeTime($value),
            default => $value,
        };

        if (in_array($type, ['date', 'datetime-local', 'time'], true) && $normalized === null) {
            return [$value, $header.' tidak valid'];
        }

        return [$normalized ?? $value, null];
    }

    private function normalizeDate(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $match)) {
            return sprintf('%04d-%02d-%02d', (int) $match[1], (int) $match[2], (int) $match[3]);
        }

        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $value, $match)) {
            return sprintf('%04d-%02d-%02d', (int) $match[3], (int) $match[2], (int) $match[1]);
        }

        return null;
    }

    private function normalizeDateTime(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})[T ](\d{1,2}):(\d{2})$/', $value, $match)) {
            return sprintf('%04d-%02d-%02dT%02d:%02d', (int) $match[1], (int) $match[2], (int) $match[3], (int) $match[4], (int) $match[5]);
        }

        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})(?:[ T](\d{1,2}):(\d{2}))?$/', $value, $match)) {
            return sprintf('%04d-%02d-%02dT%02d:%02d', (int) $match[3], (int) $match[2], (int) $match[1], (int) ($match[4] ?? 0), (int) ($match[5] ?? 0));
        }

        return null;
    }

    private function normalizeTime(string $value): ?string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $match)) {
            return sprintf('%02d:%02d', (int) $match[1], (int) $match[2]);
        }

        return null;
    }
}
