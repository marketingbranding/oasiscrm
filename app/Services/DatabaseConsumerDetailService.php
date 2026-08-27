<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\DatabaseSheetRecord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class DatabaseConsumerDetailService
{
    public const HISTORY_SHEETS = [
        'bi_checking' => 'BI Checking',
        'PSJB' => 'PSJB',
        'pemberkasan' => 'Pemberkasan',
        'proses_bank' => 'Proses Bank',
        'ppjb_dev' => 'PPJB Developer',
        'akad' => 'Akad',
        'bast' => 'BAST',
    ];

    private const ROOT_SHEET = 'data_konsumen';

    private const MAX_HISTORY_ROWS = 500;

    private const HIDDEN_FIELDS = [
        'oasis_sync_id',
        'oasis_deleted_at',
        'oasis_deleted_by',
    ];

    public function summary(Branch $branch, string $idKavling): array
    {
        $idKavling = trim($idKavling);
        $root = $this->uniqueRoot($this->rows($branch, [self::ROOT_SHEET], $idKavling, 2), $idKavling);

        return [
            'section' => 'summary',
            'identity' => [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'id_kavling' => $idKavling,
            ],
            'source' => $this->source($root),
            'fields' => $this->fields($root),
        ];
    }

    public function history(Branch $branch, string $idKavling): array
    {
        $idKavling = trim($idKavling);
        $root = $this->uniqueRoot($this->rows($branch, [self::ROOT_SHEET], $idKavling, 2), $idKavling);
        $stageOrder = array_flip(array_keys(self::HISTORY_SHEETS));
        $rows = $this->matching($this->rows($branch, array_keys(self::HISTORY_SHEETS), $idKavling, self::MAX_HISTORY_ROWS + 1), $idKavling);
        if ($rows->count() > self::MAX_HISTORY_ROWS) {
            throw ValidationException::withMessages([
                'history' => ['Riwayat proses melebihi batas aman 500 baris.'],
            ]);
        }
        $rows = $rows->sort(fn (DatabaseSheetRecord $left, DatabaseSheetRecord $right) => ($stageOrder[$left->sheet_name] <=> $stageOrder[$right->sheet_name])
            ?: ($left->row_number <=> $right->row_number));

        return [
            'section' => 'history',
            'identity' => [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'id_kavling' => $idKavling,
            ],
            'source' => $this->source($root),
            'warning' => 'Riwayat ini adalah snapshot cache spreadsheet berdasarkan ID Kavling. Penggunaan ulang ID Kavling dapat menggabungkan catatan konsumen yang berbeda.',
            'stages' => collect(self::HISTORY_SHEETS)->map(function (string $label, string $sheetName) use ($rows) {
                return [
                    'key' => $sheetName,
                    'label' => $label,
                    'items' => $rows->where('sheet_name', $sheetName)->values()->map(fn (DatabaseSheetRecord $row) => [
                        'row_number' => $row->row_number,
                        'source' => $this->source($row),
                        'fields' => $this->fields($row),
                    ])->all(),
                ];
            })->values()->all(),
        ];
    }

    private function rows(Branch $branch, array $sheetNames, string $idKavling, int $limit): Collection
    {
        return DatabaseSheetRecord::query()
            ->where('branch_id', $branch->id)
            ->whereIn('sheet_name', $sheetNames)
            ->where('row_data->id_kavling', $idKavling)
            ->whereNull('oasis_deleted_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'sheet_name', 'row_number', 'headers', 'row_data', 'updated_at']);
    }

    private function matching(Collection $rows, string $idKavling): Collection
    {
        return $rows->filter(fn (DatabaseSheetRecord $row) => array_key_exists('id_kavling', $row->row_data ?? [])
            && is_scalar($row->row_data['id_kavling'])
            && (string) $row->row_data['id_kavling'] === $idKavling);
    }

    private function uniqueRoot(Collection $rows, string $idKavling): DatabaseSheetRecord
    {
        $matches = $this->matching($rows, $idKavling)->values();

        if ($matches->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(DatabaseSheetRecord::class);
        }

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'id' => ['ID Kavling memiliki lebih dari satu data konsumen utama.'],
            ]);
        }

        return $matches->first();
    }

    private function source(DatabaseSheetRecord $row): array
    {
        return [
            'sheet_name' => $row->sheet_name,
            'row_number' => $row->row_number,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }

    private function fields(DatabaseSheetRecord $row): array
    {
        $rowData = $row->row_data ?? [];
        $keys = [];

        foreach ($row->headers ?? [] as $header) {
            if (is_string($header) && array_key_exists($header, $rowData) && ! in_array($header, $keys, true)) {
                $keys[] = $header;
            }
        }

        foreach (array_keys($rowData) as $key) {
            if (is_string($key) && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return collect($keys)
            ->filter(fn (string $key) => trim($key) !== '' && ! in_array(strtolower(trim($key)), self::HIDDEN_FIELDS, true))
            ->map(fn (string $key) => [
                'key' => $key,
                'label' => $this->label($row->sheet_name, $key),
                'type' => $this->type($row->sheet_name, $key, $rowData[$key]),
                'value' => $this->isNik($key) ? $this->maskNik($rowData[$key]) : $rowData[$key],
            ])
            ->values()
            ->all();
    }

    private function label(string $sheetName, string $key): string
    {
        $labels = DatabaseFieldConfig::config()[$sheetName]['labels'] ?? [];
        $normalizedKey = $this->normalizeKey($key);

        foreach ($labels as $configuredKey => $label) {
            if ($this->normalizeKey($configuredKey) === $normalizedKey) {
                return $label;
            }
        }

        return ucwords(str_replace(['_', '/'], ' ', $key));
    }

    private function type(string $sheetName, string $key, mixed $value): string
    {
        $config = DatabaseFieldConfig::config()[$sheetName] ?? [];
        $normalizedKey = $this->normalizeKey($key);

        if (collect($config['date'] ?? [])->contains(fn (string $field) => $this->normalizeKey($field) === $normalizedKey)) {
            return 'date';
        }

        if (collect($config['money'] ?? [])->contains(fn (string $field) => $this->normalizeKey($field) === $normalizedKey)) {
            return 'money';
        }

        if (is_bool($value) || (is_scalar($value) && in_array(strtolower((string) $value), ['true', 'false'], true))) {
            return 'boolean';
        }

        return 'text';
    }

    private function isNik(string $key): bool
    {
        $normalized = str_replace(' ', '', $this->normalizeKey($key));

        return str_contains($normalized, 'nik') || str_contains($normalized, 'ktp');
    }

    private function maskNik(mixed $value): string
    {
        $nik = trim((string) $value);

        if ($nik === '') {
            return '';
        }

        if (mb_strlen($nik) < 9) {
            return '••••••••';
        }

        return mb_substr($nik, 0, 4).'••••••••'.mb_substr($nik, -4);
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[\s_\/-]+/', ' ', $key) ?? $key));
    }
}
