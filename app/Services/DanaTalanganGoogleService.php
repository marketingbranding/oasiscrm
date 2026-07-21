<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\DanaTalangan;
use App\Models\DanaTalanganSyncStatus;
use App\Models\LeadMaster;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DanaTalanganGoogleService
{
    private SyncLockService $locks;

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

    public function __construct(private GoogleSheetsApiService $googleSheets, ?SyncLockService $locks = null)
    {
        $this->locks = $locks ?? app(SyncLockService::class);
    }

    public function sync(?int $actorId = null, bool $dryRun = false): array
    {
        $scope = config('services.google_sheets.dana_talangan_spreadsheet_id') ?: 'global';

        return $this->locks->run('dana-talangan:'.$scope, fn () => $this->performSync($actorId, $dryRun));
    }

    private function performSync(?int $actorId, bool $dryRun): array
    {
        $started = now();
        $spreadsheetId = $this->spreadsheetId();
        $status = $dryRun ? null : DanaTalanganSyncStatus::updateOrCreate(
            ['spreadsheet_id' => $spreadsheetId],
            [
                'status' => 'running', 'message' => null, 'summary' => null, 'started_at' => $started,
                'finished_at' => null, 'duration_ms' => null, 'initiated_by' => $actorId,
            ]
        );
        $summary = [
            'sheet' => $this->sheetName(),
            'matched' => 0,
            'imported' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'pushed' => 0,
            'push_failed' => 0,
            'deleted' => 0,
            'inferred_projects' => 0,
            'repaired_metadata' => 0,
            'warnings' => [],
        ];

        try {
            $sheetIds = $this->googleSheets->sheetIds($spreadsheetId);
            $sheetName = $this->sheetName();
            if (! isset($sheetIds[$sheetName])) {
                throw new \RuntimeException("Tab {$sheetName} tidak ditemukan.");
            }

            if (! $dryRun) {
                $this->ensureMetadataColumns($spreadsheetId, $sheetName, $sheetIds[$sheetName]);
            }

            $ranges = [$this->googleSheets->quoteSheetName($sheetName).'!A:Q'];
            $legacySheets = array_values(array_filter(array_keys($sheetIds), fn ($name) => $name !== $sheetName));
            foreach ($legacySheets as $legacySheet) {
                $ranges[] = $this->googleSheets->quoteSheetName($legacySheet).'!A:E';
            }
            $rowsBySheet = $this->googleSheets->batchGetRaw($spreadsheetId, $ranges, 'FORMATTED_VALUE');
            $rows = $rowsBySheet[$sheetName] ?? [];
            if (! $this->hasCanonicalHeaders($rows[0] ?? [])) {
                throw new \RuntimeException("Header tab {$sheetName} tidak sesuai format Oasis.");
            }

            $historyProjects = $this->historicalProjectMap($rowsBySheet, $legacySheets);
            $projectResolver = $this->projectResolver();
            $activeIds = [];
            $deletedIds = [];
            $matchedLocalIds = [];

            foreach (array_slice($rows, 1) as $offset => $cells) {
                $rowNumber = $offset + 2;
                $name = trim((string) ($cells[2] ?? ''));
                $syncId = trim((string) ($cells[14] ?? ''));
                $deletedAt = trim((string) ($cells[15] ?? ''));

                if ($name === '') {
                    if ($syncId !== '') {
                        $deletedIds[$syncId] = true;
                    }

                    continue;
                }

                $metadataStale = $deletedAt !== '';
                if ($metadataStale) {
                    $syncId = '';
                    $summary['repaired_metadata']++;
                }

                $resolved = $this->rowToData($cells, $historyProjects, $projectResolver, $actorId);
                if (! $resolved) {
                    if ($syncId !== '') {
                        $activeIds[$syncId] = true;
                    }
                    $summary['warnings'][] = "{$sheetName} baris {$rowNumber}: tanggal, Proyek, atau Cabang belum dapat dipetakan.";

                    continue;
                }
                [$data, $projectInferred] = $resolved;
                if ($projectInferred) {
                    $summary['inferred_projects']++;
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
                if ($record->exists) {
                    $matchedLocalIds[$record->id] = true;
                }

                if (! $dryRun) {
                    if ($record->trashed()) {
                        $record->restoreQuietly();
                    }
                    $sourceHash = $this->dataHash($data);
                    $syncMetadata = [
                        'oasis_sync_id' => $syncId,
                        'sheet_name' => $sheetName,
                        'sheet_row_number' => $rowNumber,
                        'sync_status' => 'synced',
                        'last_sync_error' => null,
                        'source_hash' => $sourceHash,
                        'last_synced_at' => now(),
                    ];
                    $localHash = $record->exists ? $this->dataHash([
                        'tanggal' => $record->tanggal?->format('Y-m-d'),
                        'nama_konsumen' => $record->nama_konsumen,
                        'kav' => $record->kav,
                        'project_name' => $record->project_name,
                        'pinjam_nama' => (bool) $record->pinjam_nama,
                        'pekerjaan' => $record->pekerjaan,
                        'status_perkawinan' => $record->status_perkawinan,
                        'umur' => $record->umur,
                        'nama_marketing' => $record->nama_marketing,
                        'tgl_komitmen' => $record->tgl_komitmen?->format('Y-m-d'),
                        'penyelesaian' => $record->penyelesaian,
                        'konfirmasi_keuangan' => (bool) $record->konfirmasi_keuangan,
                        'status' => $record->status,
                        'branch_id' => $record->branch_id,
                    ]) : null;
                    if ($record->exists && filled($record->source_hash)
                        && hash_equals((string) $record->source_hash, $sourceHash)
                        && hash_equals((string) $localHash, $sourceHash)) {
                        $summary['updated']--;
                        $summary['unchanged']++;
                        DB::table('dana_talangans')->where('id', $record->id)->update($syncMetadata);
                        $record->refresh();
                    } else {
                        $record->fill($data + ['updated_by' => null] + $syncMetadata)->saveQuietly();
                    }

                    if ($metadataStale || ($cells[14] ?? '') === '') {
                        $this->googleSheets->updateRange(
                            $spreadsheetId,
                            $this->googleSheets->quoteSheetName($sheetName)."!O{$rowNumber}:Q{$rowNumber}",
                            [[$syncId, '', '']]
                        );
                    }
                    if ($projectInferred && trim((string) ($cells[4] ?? '')) === '') {
                        $this->googleSheets->updateRange(
                            $spreadsheetId,
                            $this->googleSheets->quoteSheetName($sheetName)."!E{$rowNumber}",
                            [[$data['project_name']]]
                        );
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

            foreach (DanaTalangan::all() as $record) {
                if ($record->oasis_sync_id && isset($activeIds[$record->oasis_sync_id])) {
                    continue;
                }
                if ($record->oasis_sync_id && $record->last_synced_at && $record->sheet_name === $sheetName) {
                    $summary['deleted']++;
                    if (! $dryRun) {
                        $record->deleteQuietly();
                    }

                    continue;
                }

                if ($dryRun || $this->push($record, $actorId)) {
                    $summary['pushed']++;
                } else {
                    $summary['push_failed']++;
                    $summary['warnings'][] = 'Satu data lokal gagal dikirim ke Google Sheets.';
                }
            }

            $ok = $summary['push_failed'] === 0;
            if ($status) {
                $status->update([
                    'status' => $ok ? (empty($summary['warnings']) ? 'success' : 'warning') : 'failed',
                    'message' => empty($summary['warnings']) ? null : implode("\n", $summary['warnings']),
                    'summary' => $summary,
                    'finished_at' => now(),
                    'last_successful_at' => $ok ? now() : $status->last_successful_at,
                    'duration_ms' => $started->diffInMilliseconds(now()),
                ]);
            }

            return [
                'ok' => $ok,
                'outcome' => $ok ? (empty($summary['warnings']) ? 'success' : 'warning') : 'failed',
                'dry_run' => $dryRun,
                'message' => $ok ? null : "{$summary['push_failed']} data gagal dikirim ke Google Sheets.",
                'summary' => $summary,
            ];
        } catch (Throwable $e) {
            if ($status) {
                $status->update([
                    'status' => 'failed', 'message' => $e->getMessage(), 'finished_at' => now(),
                    'duration_ms' => $started->diffInMilliseconds(now()),
                ]);
            }

            return ['ok' => false, 'dry_run' => $dryRun, 'message' => $e->getMessage(), 'summary' => $summary];
        }
    }

    public function push(DanaTalangan $record, ?int $actorId = null): bool
    {
        try {
            $spreadsheetId = $this->spreadsheetId();
            $sheetName = $this->sheetName();
            $sheetIds = $this->googleSheets->sheetIds($spreadsheetId);
            if (! isset($sheetIds[$sheetName])) {
                throw new \RuntimeException("Tab {$sheetName} tidak ditemukan.");
            }
            $this->ensureMetadataColumns($spreadsheetId, $sheetName, $sheetIds[$sheetName]);

            $syncId = $record->oasis_sync_id ?: (string) Str::uuid();
            $rows = $this->googleSheets->batchGetRaw(
                $spreadsheetId,
                [$this->googleSheets->quoteSheetName($sheetName).'!A:Q'],
                'FORMATTED_VALUE'
            )[$sheetName] ?? [];
            $rowNumber = $this->findSyncRow($rows, $syncId) ?? $this->firstAvailableRow($rows);
            if ($rowNumber > 2 && $rowNumber > count($rows)) {
                $this->googleSheets->copyRowFormat($spreadsheetId, $sheetIds[$sheetName], 2, $rowNumber);
            }

            $this->googleSheets->updateRange(
                $spreadsheetId,
                $this->googleSheets->quoteSheetName($sheetName)."!A{$rowNumber}:Q{$rowNumber}",
                [$this->recordToRow($record, $syncId, $rowNumber)]
            );
            $record->forceFill([
                'oasis_sync_id' => $syncId,
                'sheet_name' => $sheetName,
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
            if ($record->sheet_row_number) {
                $this->clearRecordRow($record, $actorId);
            }
            $record->delete();

            return true;
        } catch (Throwable $e) {
            $record->forceFill(['sync_status' => 'failed', 'last_sync_error' => $e->getMessage()])->saveQuietly();

            return false;
        }
    }

    public function sheetName(): string
    {
        return (string) config('services.google_sheets.dana_talangan_sheet_name', 'Talangan');
    }

    public function branchIdForProject(string $project): ?int
    {
        return $this->resolveProjectBranch($project, $this->projectResolver());
    }

    private function spreadsheetId(): string
    {
        $id = (string) config('services.google_sheets.dana_talangan_spreadsheet_id');
        if ($id === '') {
            throw new \RuntimeException('DANA_TALANGAN_SHEET_ID belum dikonfigurasi.');
        }

        return $id;
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

    private function clearRecordRow(DanaTalangan $record, ?int $actorId): void
    {
        $spreadsheetId = $this->spreadsheetId();
        $sheetName = $this->sheetName();
        $rows = $this->googleSheets->batchGetRaw(
            $spreadsheetId,
            [$this->googleSheets->quoteSheetName($sheetName).'!A:Q'],
            'FORMATTED_VALUE'
        )[$sheetName] ?? [];
        $rowNumber = $this->findSyncRow($rows, (string) $record->oasis_sync_id) ?? $record->sheet_row_number;
        $values = array_fill(0, 17, '');
        $values[0] = $rowNumber - 1;
        $values[14] = $record->oasis_sync_id;
        $values[15] = now()->toIso8601String();
        $values[16] = (string) ($actorId ?? 'system');
        $this->googleSheets->updateRange(
            $spreadsheetId,
            $this->googleSheets->quoteSheetName($sheetName)."!A{$rowNumber}:Q{$rowNumber}",
            [$values]
        );
    }

    private function rowToData(array $cells, array $historyProjects, array $projectResolver, ?int $actorId): ?array
    {
        $date = $this->parseDate($cells[1] ?? null);
        $name = trim((string) ($cells[2] ?? ''));
        $project = trim((string) ($cells[4] ?? ''));
        $inferred = false;
        if ($project === '') {
            $project = $historyProjects[$this->normalize($name)] ?? '';
            $inferred = $project !== '';
        }
        $branchId = $this->resolveProjectBranch($project, $projectResolver);
        if (! $date || ! $branchId) {
            return null;
        }

        $creatorId = $actorId ?: User::where('branch_id', $branchId)->value('id') ?: User::value('id');
        if (! $creatorId) {
            return null;
        }

        return [[
            'tanggal' => $date,
            'nama_konsumen' => $name,
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
        ], $inferred];
    }

    private function projectResolver(): array
    {
        $projects = LeadMaster::where('is_active', true)->whereNotNull('branch_id')->get(['project_name', 'branch_id']);
        $exact = [];
        foreach ($projects as $project) {
            $exact[$this->normalize($project->project_name)] = (int) $project->branch_id;
        }
        $aliases = [];
        foreach (config('services.google_sheets.dana_talangan_project_branches', []) as $project => $branchCode) {
            $branchId = Branch::where('code', $branchCode)->value('id');
            if ($branchId) {
                $aliases[$this->normalize($project)] = (int) $branchId;
            }
        }

        return ['exact' => $exact, 'aliases' => $aliases, 'projects' => $projects];
    }

    private function resolveProjectBranch(string $project, array $resolver): ?int
    {
        $normalized = $this->normalize($project);
        if ($normalized === '') {
            return null;
        }
        if (isset($resolver['aliases'][$normalized])) {
            return $resolver['aliases'][$normalized];
        }
        if (isset($resolver['exact'][$normalized])) {
            return $resolver['exact'][$normalized];
        }

        $branches = [];
        foreach ($resolver['projects'] as $masterProject) {
            $master = $this->normalize($masterProject->project_name);
            if (str_starts_with($master, $normalized) || str_starts_with($normalized, $master)) {
                $branches[(int) $masterProject->branch_id] = true;
            }
        }

        return count($branches) === 1 ? (int) array_key_first($branches) : null;
    }

    private function historicalProjectMap(array $rowsBySheet, array $legacySheets): array
    {
        $projectsByName = [];
        foreach ($legacySheets as $sheetName) {
            foreach (array_slice($rowsBySheet[$sheetName] ?? [], 1) as $cells) {
                $name = $this->normalize($cells[2] ?? '');
                $project = trim((string) ($cells[4] ?? ''));
                if ($name !== '' && $project !== '') {
                    $projectsByName[$name][$this->normalize($project)] = $project;
                }
            }
        }

        $result = [];
        foreach ($projectsByName as $name => $projects) {
            if (count($projects) === 1) {
                $result[$name] = array_values($projects)[0];
            }
        }

        return $result;
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
        return DanaTalangan::whereDate('tanggal', $data['tanggal'])
            ->whereRaw('LOWER(nama_konsumen) = ?', [mb_strtolower($data['nama_konsumen'])])
            ->whereRaw('LOWER(COALESCE(kav, ?)) = ?', ['', mb_strtolower((string) $data['kav'])])
            ->get()
            ->first(fn ($record) => ! isset($matchedIds[$record->id]));
    }

    private function findSyncRow(array $rows, string $syncId): ?int
    {
        if ($syncId === '') {
            return null;
        }
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
        return hash('sha256', json_encode(array_intersect_key($data, array_flip([
            'tanggal', 'nama_konsumen', 'kav', 'project_name', 'pinjam_nama', 'pekerjaan',
            'status_perkawinan', 'umur', 'nama_marketing', 'tgl_komitmen', 'penyelesaian',
            'konfirmasi_keuangan', 'status', 'branch_id',
        ]))));
    }

    private function status($value): string
    {
        $value = str_replace(' ', '_', mb_strtolower(trim((string) $value)));

        return in_array($value, ['sanggup', 'tidak_sanggup', 'lunas'], true) ? $value : 'sanggup';
    }

    private function boolean($value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'ya', 'iya', 'yes', 'y', '✓'], true);
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
