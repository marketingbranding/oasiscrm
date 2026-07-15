<?php

namespace App\Services;

use App\Models\DanaTalangan;
use App\Models\DanaTalanganSyncStatus;
use App\Models\LeadMaster;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Throwable;

class DanaTalanganGoogleService
{
    public const VISIBLE_HEADERS = [
        'No',
        'Tanggal',
        'Nama Konsumen',
        'Kav',
        'Proyek',
        'Pinjam Nama',
        'Pekerjaan',
        'Status Kawin',
        'Umur',
        'Marketing',
        'TGL Komitmen',
        'Penyelesaian',
        'Konfirmasi',
        'Status Cicilan',
    ];

    public const META_HEADERS = ['oasis_sync_id', 'oasis_deleted_at', 'oasis_deleted_by'];

    private const MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public function __construct(private GoogleSheetsApiService $googleSheets) {}

    public function sync(?int $actorId = null, bool $dryRun = false): array
    {
        $spreadsheetId = $this->spreadsheetId();
        $status = null;
        if (! $dryRun) {
            $status = DanaTalanganSyncStatus::updateOrCreate(
                ['spreadsheet_id' => $spreadsheetId],
                ['status' => 'running', 'message' => null, 'started_at' => now(), 'finished_at' => null]
            );
        }

        $summary = [
            'tabs' => [],
            'matched' => 0,
            'imported' => 0,
            'updated' => 0,
            'pushed' => 0,
            'deleted' => 0,
            'legacy_local' => 0,
            'warnings' => [],
        ];

        try {
            $sheetIds = $this->googleSheets->sheetIds($spreadsheetId);
            $syncSheets = array_values(array_filter(array_keys($sheetIds), fn ($name) => $this->isSyncSheet($name)));
            if (! in_array($this->templateSheet(), $syncSheets, true)) {
                throw new \RuntimeException('Tab template Juli tidak ditemukan.');
            }

            $ranges = [];
            foreach ($syncSheets as $sheetName) {
                $ranges[] = $this->googleSheets->quoteSheetName($sheetName).'!A:Q';
                if (! $dryRun) {
                    $this->ensureMetadataColumns($spreadsheetId, $sheetName, $sheetIds[$sheetName]);
                }
            }

            $rowsBySheet = $this->googleSheets->batchGetRaw($spreadsheetId, $ranges, 'FORMATTED_VALUE');
            $projectBranches = $this->projectBranchMap();
            $activeIds = [];
            $deletedIds = [];
            $processedSheets = [];
            $matchedLocalIds = [];

            foreach ($syncSheets as $sheetName) {
                $rows = $rowsBySheet[$sheetName] ?? [];
                if (! $this->hasCanonicalHeaders($rows[0] ?? [])) {
                    $summary['warnings'][] = "Tab {$sheetName} dilewati karena header tidak sesuai template Juli.";

                    continue;
                }

                $summary['tabs'][] = $sheetName;
                $processedSheets[] = $sheetName;
                foreach (array_slice($rows, 1) as $offset => $cells) {
                    $rowNumber = $offset + 2;
                    $syncId = trim((string) ($cells[14] ?? ''));
                    $deletedAt = trim((string) ($cells[15] ?? ''));
                    $name = trim((string) ($cells[2] ?? ''));

                    if ($deletedAt !== '' || ($name === '' && $syncId !== '')) {
                        if ($syncId !== '') {
                            $deletedIds[$syncId] = true;
                        }

                        continue;
                    }

                    if ($name === '') {
                        continue;
                    }
                    if ($syncId !== '') {
                        $activeIds[$syncId] = true;
                    }

                    $data = $this->rowToData($cells, $projectBranches, $actorId);
                    if (! $data) {
                        $summary['warnings'][] = "{$sheetName} baris {$rowNumber}: proyek tidak dikenali atau tanggal tidak valid.";

                        continue;
                    }

                    $record = $syncId === '' ? null : DanaTalangan::withTrashed()->where('oasis_sync_id', $syncId)->first();
                    if (! $record) {
                        $record = $this->findFingerprintMatch($data, $matchedLocalIds);
                        if ($record) {
                            $summary['matched']++;
                        }
                    }

                    if (! $record) {
                        $summary['imported']++;
                        if ($dryRun) {
                            continue;
                        }
                        $record = new DanaTalangan;
                    } else {
                        $summary['updated']++;
                    }

                    $syncId = $syncId ?: ($record->oasis_sync_id ?: (string) Str::uuid());
                    $activeIds[$syncId] = true;
                    $matchedLocalIds[$record->id ?? 0] = true;
                    if (! $dryRun) {
                        if ($record->trashed()) {
                            $record->restoreQuietly();
                        }
                        $record->fill($data + [
                            'oasis_sync_id' => $syncId,
                            'sheet_name' => $sheetName,
                            'sheet_row_number' => $rowNumber,
                            'sync_status' => 'synced',
                            'last_sync_error' => null,
                            'source_hash' => $this->dataHash($data),
                            'last_synced_at' => now(),
                        ])->saveQuietly();

                        if (($cells[14] ?? '') === '') {
                            $this->googleSheets->updateRange(
                                $spreadsheetId,
                                $this->googleSheets->quoteSheetName($sheetName)."!O{$rowNumber}:Q{$rowNumber}",
                                [[$syncId, '', '']]
                            );
                        }

                        $targetSheet = $this->sheetNameForDate($record->tanggal);
                        if ($targetSheet && $targetSheet !== $sheetName) {
                            $summary['warnings'][] = "{$sheetName} baris {$rowNumber} dipindahkan ke {$targetSheet} berdasarkan tanggal.";
                            if ($this->push($record, $actorId)) {
                                $summary['pushed']++;
                            }
                        }
                    }
                }
            }

            foreach (array_diff_key($deletedIds, $activeIds) as $syncId => $_) {
                $record = DanaTalangan::withTrashed()->where('oasis_sync_id', $syncId)->first();
                if ($record && ! $record->trashed()) {
                    $summary['deleted']++;
                    if (! $dryRun) {
                        $record->deleteQuietly();
                    }
                }
            }

            $localRecords = DanaTalangan::whereDate('tanggal', '>=', '2026-07-01')->get();
            foreach ($localRecords as $record) {
                if ($record->oasis_sync_id && isset($activeIds[$record->oasis_sync_id])) {
                    continue;
                }

                if ($record->oasis_sync_id && $record->last_synced_at && in_array($record->sheet_name, $processedSheets, true)) {
                    $summary['deleted']++;
                    if (! $dryRun) {
                        $record->deleteQuietly();
                    }

                    continue;
                }

                $summary['pushed']++;
                if (! $dryRun) {
                    $this->push($record, $actorId);
                }
            }

            $summary['legacy_local'] = DanaTalangan::whereDate('tanggal', '<', '2026-07-01')->count();
            if (! $dryRun) {
                $status->update([
                    'status' => empty($summary['warnings']) ? 'success' : 'warning',
                    'message' => empty($summary['warnings']) ? null : implode("\n", $summary['warnings']),
                    'summary' => $summary,
                    'finished_at' => now(),
                ]);
            }

            return ['ok' => true, 'dry_run' => $dryRun, 'summary' => $summary];
        } catch (Throwable $e) {
            if ($status) {
                $status->update(['status' => 'failed', 'message' => $e->getMessage(), 'finished_at' => now()]);
            }

            return ['ok' => false, 'dry_run' => $dryRun, 'message' => $e->getMessage(), 'summary' => $summary];
        }
    }

    public function push(DanaTalangan $record, ?int $actorId = null): bool
    {
        try {
            $spreadsheetId = $this->spreadsheetId();
            $targetSheet = $this->sheetNameForDate($record->tanggal);
            if (! $targetSheet) {
                throw new \RuntimeException('Data sebelum Juli 2026 tidak disinkronkan ke format baru.');
            }

            $sheetIds = $this->googleSheets->sheetIds($spreadsheetId);
            if (! isset($sheetIds[$targetSheet])) {
                $sheetIds[$targetSheet] = $this->createMonthSheet($spreadsheetId, $targetSheet, $sheetIds);
            }
            $this->ensureMetadataColumns($spreadsheetId, $targetSheet, $sheetIds[$targetSheet]);

            $syncId = $record->oasis_sync_id ?: (string) Str::uuid();
            if ($record->sheet_name && $record->sheet_name !== $targetSheet && $record->sheet_row_number) {
                $this->clearRecordRow($record, $actorId);
                $record->sheet_row_number = null;
            }

            $rows = $this->googleSheets->batchGetRaw(
                $spreadsheetId,
                [$this->googleSheets->quoteSheetName($targetSheet).'!A:Q'],
                'FORMATTED_VALUE'
            )[$targetSheet] ?? [];
            $rowNumber = $this->findSyncRow($rows, $syncId)
                ?? ($record->sheet_name === $targetSheet ? $record->sheet_row_number : null)
                ?? $this->firstAvailableRow($rows);

            if ($rowNumber > 2 && $rowNumber > count($rows)) {
                $this->googleSheets->copyRowFormat($spreadsheetId, $sheetIds[$targetSheet], 2, $rowNumber);
            }

            $values = $this->recordToRow($record, $syncId, $rowNumber);
            $this->googleSheets->updateRange(
                $spreadsheetId,
                $this->googleSheets->quoteSheetName($targetSheet)."!A{$rowNumber}:Q{$rowNumber}",
                [$values]
            );

            $record->forceFill([
                'oasis_sync_id' => $syncId,
                'sheet_name' => $targetSheet,
                'sheet_row_number' => $rowNumber,
                'sync_status' => 'synced',
                'last_sync_error' => null,
                'source_hash' => $this->dataHash($record->toArray()),
                'last_synced_at' => now(),
            ])->saveQuietly();

            return true;
        } catch (Throwable $e) {
            $record->forceFill(['sync_status' => 'failed', 'last_sync_error' => $e->getMessage()])->saveQuietly();

            return false;
        }
    }

    public function delete(DanaTalangan $record, ?int $actorId = null): bool
    {
        try {
            if ($record->sheet_name && $record->sheet_row_number) {
                $this->clearRecordRow($record, $actorId);
            }
            $record->delete();

            return true;
        } catch (Throwable $e) {
            $record->forceFill(['sync_status' => 'failed', 'last_sync_error' => $e->getMessage()])->saveQuietly();

            return false;
        }
    }

    public function tabs(): array
    {
        $status = DanaTalanganSyncStatus::where('spreadsheet_id', $this->spreadsheetId())->first();
        $tabs = $status?->summary['tabs'] ?? [];
        foreach (DanaTalangan::whereDate('tanggal', '>=', '2026-07-01')->pluck('tanggal') as $date) {
            $tabs[] = $this->sheetNameForDate($date);
        }
        $tabs = array_values(array_unique(array_filter($tabs)));
        if (! in_array($this->templateSheet(), $tabs, true)) {
            $tabs[] = $this->templateSheet();
        }

        usort($tabs, function ($left, $right) {
            $leftRange = $this->dateRangeForSheet($left);
            $rightRange = $this->dateRangeForSheet($right);

            return ($leftRange[0] ?? $left) <=> ($rightRange[0] ?? $right);
        });

        return $tabs;
    }

    public function sheetNameForDate($date): ?string
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        if ($date->lt(Carbon::create(2026, 7, 1))) {
            return null;
        }

        $name = self::MONTHS[$date->month];

        return $date->year === 2026 ? $name : $name.' '.$date->year;
    }

    public function dateRangeForSheet(string $sheetName): ?array
    {
        $year = 2026;
        $monthName = $sheetName;
        if (preg_match('/^(.+)\s+(20\d{2})$/u', $sheetName, $match)) {
            $monthName = $match[1];
            $year = (int) $match[2];
        }

        $month = array_search($monthName, self::MONTHS, true);
        if ($month === false || ($year === 2026 && $month < 7)) {
            return null;
        }

        $start = Carbon::create($year, $month, 1)->startOfDay();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    private function spreadsheetId(): string
    {
        $id = (string) config('services.google_sheets.dana_talangan_spreadsheet_id');
        if ($id === '') {
            throw new \RuntimeException('DANA_TALANGAN_SHEET_ID belum dikonfigurasi.');
        }

        return $id;
    }

    private function templateSheet(): string
    {
        return (string) config('services.google_sheets.dana_talangan_template_sheet', 'Juli');
    }

    private function isSyncSheet(string $name): bool
    {
        if ($name === $this->templateSheet()) {
            return true;
        }
        foreach (array_slice(self::MONTHS, 7, null, true) as $month) {
            if ($name === $month) {
                return true;
            }
        }

        return (bool) preg_match('/^('.implode('|', self::MONTHS).')\s+(20\d{2})$/u', $name, $match)
            && (int) $match[2] >= 2027;
    }

    private function ensureMetadataColumns(string $spreadsheetId, string $sheetName, int $sheetId): void
    {
        $this->googleSheets->updateRange(
            $spreadsheetId,
            $this->googleSheets->quoteSheetName($sheetName).'!O1:Q1',
            [self::META_HEADERS]
        );
        $this->googleSheets->hideColumns($spreadsheetId, $sheetId, 14, 17);
    }

    private function createMonthSheet(string $spreadsheetId, string $sheetName, array $sheetIds): int
    {
        $templateId = $sheetIds[$this->templateSheet()] ?? null;
        if (! $templateId) {
            throw new \RuntimeException('Tab template Juli tidak ditemukan.');
        }

        $sheetId = $this->googleSheets->duplicateSheet($spreadsheetId, $templateId, $sheetName);
        $quoted = $this->googleSheets->quoteSheetName($sheetName);
        $this->googleSheets->clearRange($spreadsheetId, $quoted.'!A2:Q1000');
        $numbers = array_map(fn ($number) => [$number], range(1, 100));
        $this->googleSheets->updateRange($spreadsheetId, $quoted.'!A2:A101', $numbers);
        $this->ensureMetadataColumns($spreadsheetId, $sheetName, $sheetId);

        return $sheetId;
    }

    private function clearRecordRow(DanaTalangan $record, ?int $actorId): void
    {
        $spreadsheetId = $this->spreadsheetId();
        $range = $this->googleSheets->quoteSheetName($record->sheet_name)."!A{$record->sheet_row_number}:Q{$record->sheet_row_number}";
        $values = array_fill(0, 17, '');
        $values[0] = $record->sheet_row_number - 1;
        $values[14] = $record->oasis_sync_id;
        $values[15] = now()->toIso8601String();
        $values[16] = (string) ($actorId ?? 'system');
        $this->googleSheets->updateRange($spreadsheetId, $range, [$values]);
    }

    private function hasCanonicalHeaders(array $headers): bool
    {
        foreach (self::VISIBLE_HEADERS as $index => $header) {
            if ($this->normalize($headers[$index] ?? '') !== $this->normalize($header)) {
                return false;
            }
        }

        return true;
    }

    private function rowToData(array $cells, array $projectBranches, ?int $actorId): ?array
    {
        $date = $this->parseDate($cells[1] ?? null);
        $project = trim((string) ($cells[4] ?? ''));
        $branchId = $projectBranches[$this->normalize($project)] ?? null;
        if (! $date || ! $this->sheetNameForDate($date) || ! $branchId) {
            return null;
        }

        $creatorId = $actorId ?: User::where('branch_id', $branchId)->value('id') ?: User::value('id');
        if (! $creatorId) {
            return null;
        }

        return [
            'tanggal' => $date,
            'nama_konsumen' => trim((string) ($cells[2] ?? '')),
            'kav' => $this->nullable($cells[3] ?? null),
            'project_name' => $project,
            'pinjam_nama' => $this->boolean($cells[5] ?? null),
            'pekerjaan' => $this->nullable($cells[6] ?? null),
            'status_perkawinan' => $this->nullable($cells[7] ?? null),
            'umur' => is_numeric($cells[8] ?? null) ? (int) $cells[8] : null,
            'nama_marketing' => $this->nullable($cells[9] ?? null),
            'tgl_komitmen' => $this->parseDate($cells[10] ?? null),
            'penyelesaian' => $this->nullable($cells[11] ?? null),
            'konfirmasi_keuangan' => $this->boolean($cells[12] ?? null),
            'status' => $this->status($cells[13] ?? null),
            'branch_id' => $branchId,
            'created_by' => $creatorId,
        ];
    }

    private function recordToRow(DanaTalangan $record, string $syncId, int $rowNumber): array
    {
        return [
            $rowNumber - 1,
            $record->tanggal?->format('Y-m-d'),
            $record->nama_konsumen,
            $record->kav ?? '',
            $record->project_name ?? '',
            $record->pinjam_nama ? 'YA' : 'TIDAK',
            $record->pekerjaan ?? '',
            $record->status_perkawinan ?? '',
            $record->umur ?? '',
            $record->nama_marketing ?? '',
            $record->tgl_komitmen?->format('Y-m-d') ?? '',
            $record->penyelesaian ?? '',
            $record->konfirmasi_keuangan,
            $record->status,
            $syncId,
            '',
            '',
        ];
    }

    private function findFingerprintMatch(array $data, array $matchedIds): ?DanaTalangan
    {
        return DanaTalangan::whereNull('oasis_sync_id')
            ->whereDate('tanggal', $data['tanggal'])
            ->whereRaw('LOWER(nama_konsumen) = ?', [mb_strtolower($data['nama_konsumen'])])
            ->whereRaw('LOWER(COALESCE(kav, ?)) = ?', ['', mb_strtolower((string) $data['kav'])])
            ->whereRaw('LOWER(COALESCE(project_name, ?)) = ?', ['', mb_strtolower((string) $data['project_name'])])
            ->get()
            ->first(fn ($record) => ! isset($matchedIds[$record->id]));
    }

    private function findSyncRow(array $rows, string $syncId): ?int
    {
        foreach (array_slice($rows, 1) as $offset => $cells) {
            if (($cells[14] ?? '') === $syncId) {
                return $offset + 2;
            }
        }

        return null;
    }

    private function firstAvailableRow(array $rows): int
    {
        foreach (array_slice($rows, 1) as $offset => $cells) {
            if (trim((string) ($cells[2] ?? '')) === '') {
                return $offset + 2;
            }
        }

        return max(2, count($rows) + 1);
    }

    private function projectBranchMap(): array
    {
        return LeadMaster::where('is_active', true)
            ->whereNotNull('branch_id')
            ->get(['project_name', 'branch_id'])
            ->mapWithKeys(fn ($project) => [$this->normalize($project->project_name) => $project->branch_id])
            ->all();
    }

    private function parseDate($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->format('Y-m-d');
                }
            } catch (Throwable) {
            }
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function dataHash(array $data): string
    {
        $fields = array_intersect_key($data, array_flip([
            'tanggal', 'nama_konsumen', 'kav', 'project_name', 'pinjam_nama', 'pekerjaan',
            'status_perkawinan', 'umur', 'nama_marketing', 'tgl_komitmen', 'penyelesaian',
            'konfirmasi_keuangan', 'status', 'branch_id',
        ]));

        return hash('sha256', json_encode($fields));
    }

    private function status($value): string
    {
        $value = str_replace(' ', '_', mb_strtolower(trim((string) $value)));

        return in_array($value, ['sanggup', 'tidak_sanggup', 'lunas'], true) ? $value : 'sanggup';
    }

    private function boolean($value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'ya', 'yes', 'y', '✓'], true);
    }

    private function nullable($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalize($value): string
    {
        return mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', trim((string) $value)));
    }
}
