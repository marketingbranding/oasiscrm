<?php

namespace App\Services;

use App\Exceptions\DatabaseConsumerDetailException;
use App\Models\Branch;
use App\Models\DatabaseSheetRecord;
use Illuminate\Database\Eloquent\Collection;

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

    private const CHAIN = [
        'data_konsumen' => ['incoming' => null, 'produced' => 'no_ktp'],
        'bi_checking' => ['incoming' => 'no_ktp', 'produced' => 'id_kons'],
        'PSJB' => ['incoming' => 'id_kons', 'produced' => 'id_psjb'],
        'pemberkasan' => ['incoming' => 'id_psjb', 'produced' => 'id_berkas'],
        'proses_bank' => ['incoming' => 'id_berkas', 'produced' => 'no_sp3k'],
        'ppjb_dev' => ['incoming' => 'no_sp3k', 'produced' => 'id_ppjb_dev'],
        'akad' => ['incoming' => 'id_ppjb_dev', 'produced' => 'no_ppjb_akad'],
        'bast' => ['incoming' => 'no_ppjb_akad', 'produced' => 'no_bast'],
    ];

    private const MAX_HISTORY_ROWS = 500;

    private const HIDDEN_FIELDS = [
        'oasis_sync_id',
        'oasis_deleted_at',
        'oasis_deleted_by',
    ];

    public function summary(Branch $branch, int $recordId): array
    {
        $anchor = $this->anchor($branch, $recordId);
        $root = $this->resolveRoot($branch, $anchor);
        [$historyAvailable, $reason] = $this->historyAvailability($branch, $root);

        return [
            'section' => 'summary',
            'identity' => $this->identity($branch, $root, $anchor, $historyAvailable, $reason),
            'source' => $this->source($root),
            'fields' => $this->fields($root),
        ];
    }

    public function history(Branch $branch, int $recordId): array
    {
        $anchor = $this->anchor($branch, $recordId);
        $root = $this->resolveRoot($branch, $anchor);
        $nik = $this->value($root, 'no_ktp');

        if ($nik === '') {
            throw $this->error('consumer_chain_broken', 'Rantai konsumen tidak lengkap.');
        }

        $roots = $this->exactRows($branch, 'data_konsumen', 'no_ktp', [$nik], 2);
        if ($roots->count() !== 1 || $roots->first()->id !== $root->id) {
            throw $this->error('ambiguous_consumer_identity', 'Identitas konsumen tidak dapat ditentukan secara unik.');
        }

        $frontier = [$nik];
        $total = 0;
        $warnings = [];
        $diagnostics = [];
        $stages = [];
        $rootKavling = $this->value($root, 'id_kavling');

        foreach (self::HISTORY_SHEETS as $sheetName => $label) {
            $config = self::CHAIN[$sheetName];
            $rows = $frontier === []
                ? new Collection
                : $this->exactRows($branch, $sheetName, $config['incoming'], $frontier, self::MAX_HISTORY_ROWS + 1);

            $total += $rows->count();
            if ($rows->count() > self::MAX_HISTORY_ROWS || $total > self::MAX_HISTORY_ROWS) {
                throw $this->error('history_limit_exceeded', 'Riwayat proses melebihi batas aman 500 baris.');
            }

            $this->assertProducedIdsBelongToRows($branch, $sheetName, $config['produced'], $rows);
            $next = [];
            $items = [];
            $stageWarnings = [];

            foreach ($rows->sortBy('row_number') as $row) {
                $itemWarnings = [];
                $produced = $this->value($row, $config['produced']);
                if ($produced === '') {
                    $message = "Baris {$row->row_number} tidak memiliki {$config['produced']}; tahap berikutnya tidak ditelusuri dari baris ini.";
                    $itemWarnings[] = $message;
                    $stageWarnings[] = $message;
                    $warnings[] = $message;
                    $diagnostics[] = $this->diagnostic('blank_chain_id', $row, $message);
                } else {
                    $next[$produced] = true;
                }

                $stageKavling = $this->value($row, 'id_kavling');
                if ($rootKavling !== '' && $stageKavling !== '' && $stageKavling !== $rootKavling) {
                    $message = "ID Kavling pada baris {$row->row_number} berbeda dari data konsumen utama.";
                    $itemWarnings[] = $message;
                    $stageWarnings[] = $message;
                    $warnings[] = $message;
                    $diagnostics[] = $this->diagnostic('kavling_mismatch', $row, $message);
                }

                $items[] = [
                    'record_id' => $row->id,
                    'row_number' => $row->row_number,
                    'source' => $this->source($row),
                    'fields' => $this->fields($row),
                    'warnings' => $itemWarnings,
                ];
            }

            $stages[] = [
                'key' => $sheetName,
                'label' => $label,
                'warnings' => array_values(array_unique($stageWarnings)),
                'items' => $items,
            ];
            $frontier = array_keys($next);
        }

        return [
            'section' => 'history',
            'identity' => $this->identity($branch, $root, $anchor, true, null),
            'source' => $this->source($root),
            'basis' => 'canonical_chain',
            'warnings' => array_values(array_unique($warnings)),
            'diagnostics' => $diagnostics,
            'stages' => $stages,
        ];
    }

    private function anchor(Branch $branch, int $recordId): DatabaseSheetRecord
    {
        $record = DatabaseSheetRecord::query()
            ->whereKey($recordId)
            ->where('branch_id', $branch->id)
            ->whereIn('sheet_name', array_keys(self::CHAIN))
            ->whereNull('oasis_deleted_at')
            ->first($this->columns());

        if (! $record) {
            throw $this->error('consumer_not_found', 'Detail konsumen tidak ditemukan.', 404);
        }

        return $record;
    }

    private function resolveRoot(Branch $branch, DatabaseSheetRecord $anchor): DatabaseSheetRecord
    {
        $sheets = array_keys(self::CHAIN);
        $index = array_search($anchor->sheet_name, $sheets, true);
        $current = new Collection([$anchor]);

        for ($stage = $index; $stage > 0; $stage--) {
            $incoming = self::CHAIN[$sheets[$stage]]['incoming'];
            $values = $current->map(fn (DatabaseSheetRecord $row) => $this->value($row, $incoming))->filter()->unique()->values()->all();
            if ($values === []) {
                throw $this->error('consumer_chain_broken', 'Rantai konsumen tidak lengkap.');
            }

            $previousSheet = $sheets[$stage - 1];
            $produced = self::CHAIN[$previousSheet]['produced'];
            $parents = $this->exactRows($branch, $previousSheet, $produced, $values, self::MAX_HISTORY_ROWS + 1);
            if ($parents->count() > self::MAX_HISTORY_ROWS) {
                throw $this->error('history_limit_exceeded', 'Riwayat proses melebihi batas aman 500 baris.');
            }
            $resolvedValues = $parents->map(fn (DatabaseSheetRecord $row) => $this->value($row, $produced))->unique();
            if ($parents->isEmpty() || collect($values)->diff($resolvedValues)->isNotEmpty()) {
                throw $this->error('consumer_chain_broken', 'Rantai konsumen tidak lengkap.');
            }
            $current = $parents;
        }

        $roots = $current->unique('id')->values();
        if ($roots->count() !== 1) {
            throw $this->error('ambiguous_consumer_identity', 'Identitas konsumen tidak dapat ditentukan secara unik.');
        }

        return $roots->first();
    }

    private function historyAvailability(Branch $branch, DatabaseSheetRecord $root): array
    {
        $nik = $this->value($root, 'no_ktp');
        if ($nik === '') {
            return [false, 'consumer_chain_broken'];
        }

        $roots = $this->exactRows($branch, 'data_konsumen', 'no_ktp', [$nik], 2);
        if ($roots->count() !== 1 || $roots->first()->id !== $root->id) {
            throw $this->error('ambiguous_consumer_identity', 'Identitas konsumen tidak dapat ditentukan secara unik.');
        }

        return [true, null];
    }

    private function exactRows(Branch $branch, string $sheetName, string $field, array $values, int $limit): Collection
    {
        $values = array_values(array_unique(array_filter(array_map(fn ($value) => trim((string) $value), $values), fn (string $value) => $value !== '')));
        if ($values === []) {
            return new Collection;
        }

        $rows = DatabaseSheetRecord::query()
            ->where('branch_id', $branch->id)
            ->where('sheet_name', $sheetName)
            ->whereNull('oasis_deleted_at')
            ->where(function ($query) use ($field, $values) {
                foreach ($values as $value) {
                    $query->orWhere("row_data->{$field}", $value);
                }
            })
            ->orderBy('id')
            ->limit($limit)
            ->get($this->columns());

        return $rows->filter(fn (DatabaseSheetRecord $row) => in_array($this->value($row, $field), $values, true))->values();
    }

    private function assertProducedIdsBelongToRows(Branch $branch, string $sheetName, string $field, Collection $rows): void
    {
        $values = $rows->map(fn (DatabaseSheetRecord $row) => $this->value($row, $field))->filter()->unique()->values()->all();
        if ($values === []) {
            return;
        }

        $allProducers = $this->exactRows($branch, $sheetName, $field, $values, self::MAX_HISTORY_ROWS + 1);
        if ($allProducers->count() > self::MAX_HISTORY_ROWS || $allProducers->pluck('id')->diff($rows->pluck('id'))->isNotEmpty()) {
            throw $this->error('ambiguous_chain_id', 'Rantai konsumen tidak dapat ditentukan secara unik.');
        }
    }

    private function identity(Branch $branch, DatabaseSheetRecord $root, DatabaseSheetRecord $anchor, bool $historyAvailable, ?string $reason): array
    {
        return [
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'id_kavling' => $this->value($root, 'id_kavling'),
            'anchor' => [
                'record_id' => $anchor->id,
                'sheet_name' => $anchor->sheet_name,
                'row_number' => $anchor->row_number,
            ],
            'basis' => 'canonical_chain',
            'history_available' => $historyAvailable,
            'history_unavailable_reason' => $reason,
        ];
    }

    private function value(DatabaseSheetRecord $row, string $field): string
    {
        $value = ($row->row_data ?? [])[$field] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function columns(): array
    {
        return ['id', 'sheet_name', 'row_number', 'headers', 'row_data', 'updated_at'];
    }

    private function diagnostic(string $code, DatabaseSheetRecord $row, string $message): array
    {
        return [
            'code' => $code,
            'sheet_name' => $row->sheet_name,
            'row_number' => $row->row_number,
            'message' => $message,
        ];
    }

    private function error(string $code, string $message, int $status = 422): DatabaseConsumerDetailException
    {
        return new DatabaseConsumerDetailException($code, $status, $message);
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
