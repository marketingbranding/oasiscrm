<?php

namespace App\Services;

use App\Support\DatabaseV2ModuleConfig;
use Illuminate\Support\Facades\DB;

class DatabaseV2ImportService
{
    public const MAX_ROWS = 1000;

    public function preview(string $module, string $raw): array
    {
        $config = DatabaseV2ModuleConfig::config($module);
        if (! $config) {
            return ['error' => 'Modul tidak dikenal.'];
        }

        $fields = $config['fields'];
        $fieldLookup = [];
        foreach ($fields as $f) {
            $fieldLookup[$this->headerKey($f)] = $f;
        }

        $ignoredHeaders = $config['ignored_import_headers'] ?? [];
        $ignoredLookup = [];
        foreach ($ignoredHeaders as $h) {
            $ignoredLookup[$this->headerKey($h)] = $h;
        }
        foreach (DatabaseV2ModuleConfig::LEGACY_IGNORED as $h) {
            $ignoredLookup[$this->headerKey($h)] = $h;
        }

        $parsed = $this->parse($raw);

        $unknown = [];
        $ignored = [];
        foreach ($parsed['headers'] as $h) {
            if ($h === '') {
                continue;
            }
            $key = $this->headerKey($h);
            if (isset($fieldLookup[$key])) {
                continue;
            }
            if (isset($ignoredLookup[$key])) {
                $ignored[] = $h;

                continue;
            }
            $unknown[] = $h;
        }
        if ($unknown !== []) {
            $list = implode(', ', array_values(array_unique($unknown)));

            return ['error' => 'Kolom tidak dikenal: '.$list];
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
                $actual = $fieldLookup[$key] ?? null;
                if (! $actual) {
                    continue;
                }
                $normalized[$actual] = trim((string) $value);
            }

            foreach ($config['date'] as $dateField) {
                if (isset($normalized[$dateField]) && $normalized[$dateField] !== '') {
                    $norm = $this->normalizeDate($normalized[$dateField]);
                    if ($norm === null) {
                        $errors[] = DatabaseV2ModuleConfig::labels()[$dateField] ?? $dateField;
                    } else {
                        $normalized[$dateField] = $norm;
                    }
                }
            }

            foreach ($config['integer'] as $intField) {
                if (isset($normalized[$intField]) && $normalized[$intField] !== '') {
                    $cleaned = preg_replace('/[^\d-]/', '', $normalized[$intField]);
                    if (! is_numeric($cleaned)) {
                        $errors[] = DatabaseV2ModuleConfig::labels()[$intField] ?? $intField;
                    } else {
                        $normalized[$intField] = (int) $cleaned;
                    }
                }
            }

            foreach ($config['money'] as $moneyField) {
                if (isset($normalized[$moneyField]) && $normalized[$moneyField] !== '') {
                    $cleaned = preg_replace('/[^\d.,-]/', '', $normalized[$moneyField]);
                    $cleaned = str_replace(['.', ','], ['', '.'], $cleaned);
                    if (! is_numeric($cleaned)) {
                        $errors[] = DatabaseV2ModuleConfig::labels()[$moneyField] ?? $moneyField;
                    } else {
                        $normalized[$moneyField] = $cleaned;
                    }
                }
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
            'module' => $module,
            'headers' => $fields,
            'ignored_headers' => array_values(array_unique($ignored)),
            'rows' => $rows,
            'valid_count' => $validCount,
            'invalid_count' => $invalidCount,
            'has_invalid' => $invalidCount > 0,
        ];
    }

    public function save(string $module, string $raw, int $branchId, int $userId, bool $validOnly = false): array
    {
        $preview = $this->preview($module, $raw);

        if (isset($preview['error'])) {
            return ['saved' => 0, 'failed' => 0, 'total' => 0, 'message' => $preview['error']];
        }

        if ($preview['has_invalid'] && ! $validOnly) {
            return ['saved' => 0, 'failed' => $preview['invalid_count'], 'total' => $preview['invalid_count'], 'message' => 'Import diblok: terdapat '.$preview['invalid_count'].' baris tidak valid. Perbaiki data atau gunakan "Import valid rows only".'];
        }

        $config = DatabaseV2ModuleConfig::config($module);
        $modelClass = $config['model'];
        $fields = $config['fields'];

        $saved = 0;
        $failed = 0;

        $rowsToInsert = [];
        foreach ($preview['rows'] as $row) {
            if ($row['status'] !== 'VALID') {
                $failed++;

                continue;
            }
            $data = $row['values'];
            $data['branch_id'] = $branchId;
            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;
            $data['created_at'] = now();
            $data['updated_at'] = now();
            $rowsToInsert[] = $data;
        }

        if ($rowsToInsert !== []) {
            $table = (new $modelClass)->getTable();
            DB::table($table)->insert($rowsToInsert);
            $saved = count($rowsToInsert);
        }

        return [
            'saved' => $saved,
            'failed' => $failed,
            'total' => $saved + $failed,
            'message' => "Berhasil disimpan: {$saved}".($failed > 0 ? "\nGagal: {$failed}" : ''),
        ];
    }

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

        $headers = array_map(fn (string $h) => trim($h), explode("\t", $lines[0]));
        $rows = [];

        for ($i = 1; $i < count($lines); $i++) {
            $cells = explode("\t", $lines[$i]);
            $row = [];
            foreach ($headers as $col => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = trim((string) ($cells[$col] ?? ''));
            }
            if (count(array_filter($row, fn (string $v) => $v !== '')) > 0) {
                $rows[] = $row;
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function headerKey(string $header): string
    {
        $key = strtolower(trim($header));
        $key = str_replace(['_', '-', '/'], ' ', $key);

        return preg_replace('/\s+/', ' ', $key) ?? $key;
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2})$/', $value, $m)) {
            $year = (int) $m[3];
            $year = $year < 50 ? 2000 + $year : 1900 + $year;

            return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
        }

        return null;
    }
}
