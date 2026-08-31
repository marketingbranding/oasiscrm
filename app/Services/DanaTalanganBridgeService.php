<?php

namespace App\Services;

use App\Enums\DanaTalanganReconciliationStatus;
use App\Models\ActivityLog;
use App\Models\DanaTalangan;
use App\Models\DanaTalanganBridgeSetting;
use App\Models\DanaTalanganReconciliationItem;
use App\Models\DanaTalanganSyncStatus;
use App\Models\LeadMaster;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class DanaTalanganBridgeService
{
    private const OWNED_FIELDS = [
        'No', 'Tanggal', 'Nama Konsumen', 'Kav', 'Proyek', 'Pinjam Nama', 'Pekerjaan', 'Status Kawin', 'Umur', 'Marketing',
    ];

    private const SHARED_FIELDS = ['TGL Komitmen', 'Penyelesaian', 'Konfirmasi', 'Status Cicilan'];

    public function __construct(
        private readonly DanaTalanganBridgeModeService $modes,
        private readonly DanaTalanganSpreadsheetContract $contracts,
        private readonly DanaTalanganSpreadsheetWriter $writer,
        private readonly SyncLockService $locks,
    ) {}

    public function preflight(): DanaTalanganBridgeSetting
    {
        try {
            $contract = $this->contracts->resolve();

            return DanaTalanganBridgeSetting::query()->updateOrCreate(
                ['spreadsheet_id' => $contract->spreadsheetId],
                ['status' => 'success', 'preflight_at' => now(), 'preflight_hash' => $contract->hash],
            );
        } catch (Throwable $exception) {
            report($exception);
            $spreadsheetId = trim((string) config('services.google_sheets.dana_talangan_spreadsheet_id'));
            if ($spreadsheetId !== '') {
                DanaTalanganBridgeSetting::query()->updateOrCreate(
                    ['spreadsheet_id' => $spreadsheetId],
                    ['mode' => 'off', 'status' => 'failed', 'preflight_at' => now(), 'preflight_hash' => null],
                );
            }
            throw new \DomainException('Preflight bridge Dana Talangan gagal.');
        }
    }

    public function push(DanaTalangan $record, ?User $actor = null): array
    {
        if (! $this->modes->isPushEnabled()) {
            return ['ok' => false, 'status' => 'disabled'];
        }

        return $this->locks->runOrThrow($this->lockKey(), fn () => $this->pushUnlocked($record, $actor));
    }

    private function pushUnlocked(DanaTalangan $record, ?User $actor): array
    {
        $spreadsheetId = $this->modes->spreadsheetId();
        $record = DB::transaction(function () use ($record, $spreadsheetId): DanaTalangan {
            $locked = DanaTalangan::query()->lockForUpdate()->findOrFail($record->id);
            if ($locked->remote_target_spreadsheet_id !== null && $locked->remote_target_spreadsheet_id !== $spreadsheetId) {
                throw new \DomainException('Tujuan remote Dana Talangan tidak sesuai konfigurasi saat ini.');
            }
            $locked->update([
                'oasis_sync_id' => $locked->oasis_sync_id ?: (string) Str::uuid(),
                'remote_target_spreadsheet_id' => $spreadsheetId,
                'delivery_attempted_at' => now(),
            ]);

            return $locked->fresh(['project', 'branch']);
        });

        try {
            $contract = $this->contracts->resolve();
            if ($contract->spreadsheetId !== $spreadsheetId) {
                throw new \DomainException('Kontrak spreadsheet Dana Talangan berubah saat pengiriman.');
            }
            $rows = $this->contracts->rows($contract);
            $syncId = (string) $record->oasis_sync_id;
            $matches = array_values(array_filter($rows, fn (array $row) => $row['oasis_sync_id'] === $syncId));
            if (! Str::isUuid($syncId) || count($matches) > 1) {
                $this->issue($record, 'duplicate_or_invalid_uuid', null, ['oasis_sync_id'], ['sync_id_hash' => $this->hash($syncId)]);

                return $this->mark($record, 'conflict');
            }
            $remote = $matches[0] ?? null;
            if ($remote !== null && filled($remote['oasis_deleted_at'])) {
                $this->issue($record, 'remote_tombstone', $remote);

                return $this->mark($record, 'conflict');
            }

            $localPayload = $this->canonicalPayload($record);
            if ($remote === null) {
                if ($this->baseline($record) !== null) {
                    $this->issue($record, 'remote_missing', null);

                    return $this->mark($record, 'conflict');
                }
                $result = $this->writer->append($this->toCells($localPayload), $syncId, false);

                return $this->afterWrite($record, $result->rowValues, $localPayload, $actor);
            }

            $remotePayload = $this->normalizeRemote($remote);
            if ($this->baseline($record) !== null && $record->last_synced_field_hashes === null) {
                if (! hash_equals($this->payloadHash($localPayload), $this->payloadHash($remotePayload))) {
                    $this->issue($record, 'baseline_missing', $remote);

                    return $this->mark($record, 'conflict');
                }
                $this->recordBaseline($record, $remotePayload, $remote['_row_number'], $actor);

                return ['ok' => true, 'status' => 'synced'];
            }
            if ($this->baseline($record) === null) {
                if (! hash_equals($this->payloadHash($localPayload), $this->payloadHash($remotePayload))) {
                    $this->issue($record, 'baseline_missing', $remote);

                    return $this->mark($record, 'conflict');
                }
                $this->recordBaseline($record, $remotePayload, $remote['_row_number'], $actor);

                return ['ok' => true, 'status' => 'synced'];
            }

            $decision = $this->threeWay($record, $localPayload, $remotePayload);
            if ($decision['owned_conflict'] || $decision['shared_conflicts'] !== []) {
                $fields = [...$decision['owned_conflicts'], ...$decision['shared_conflicts']];
                $this->issue($record, 'remote_conflict', $remote, $fields);

                return $this->mark($record, 'conflict');
            }
            if ($decision['remote_shared'] !== []) {
                $this->pullShared($record, $remotePayload, $decision['remote_shared'], $remote['_row_number'], $actor);
                $record->refresh();
                $localPayload = $this->canonicalPayload($record);
                if (! $decision['local_changed']) {
                    return ['ok' => true, 'status' => 'synced'];
                }
            }
            if ($decision['local_changed']) {
                $result = $this->writer->update($syncId, $this->toCells($localPayload), false);

                return $this->afterWrite($record, $result->rowValues, $localPayload, $actor);
            }

            $this->recordBaseline($record, $remotePayload, $remote['_row_number'], $actor, false);

            return ['ok' => true, 'status' => 'synced'];
        } catch (Throwable $exception) {
            report($exception);
            $this->mark($record, 'sync_failed', 'Sinkronisasi spreadsheet gagal.');

            return ['ok' => false, 'status' => 'sync_failed'];
        }
    }

    public function pull(?User $actor = null, bool $dryRun = false): array
    {
        if (! $this->modes->isPullEnabled()) {
            return ['ok' => false, 'status' => 'disabled', 'summary' => $this->emptySummary()];
        }

        return $this->locks->run($this->lockKey(), fn () => $this->pullUnlocked($actor, $dryRun));
    }

    private function pullUnlocked(?User $actor, bool $dryRun): array
    {
        $started = now();
        $status = $dryRun ? null : DanaTalanganSyncStatus::query()->updateOrCreate(
            ['spreadsheet_id' => $this->modes->spreadsheetId()],
            ['status' => 'running', 'message' => null, 'summary' => null, 'started_at' => $started, 'finished_at' => null, 'initiated_by' => $actor?->id],
        );
        $summary = $this->emptySummary();
        try {
            $contract = $this->contracts->resolve();
            $rows = $this->contracts->rows($contract);
            $counts = array_count_values(array_filter(array_map(fn (array $row) => $row['oasis_sync_id'], $rows)));
            $seen = [];

            foreach ($rows as $row) {
                $syncId = $row['oasis_sync_id'];
                $business = collect(self::OWNED_FIELDS)->merge(self::SHARED_FIELDS)->reject(fn (string $field) => $field === 'No')->contains(fn (string $field) => filled($row[$field]));
                $invalidFields = $this->invalidRemoteFields($row);
                if ($invalidFields !== []) {
                    $this->reconcile(null, 'remote_data_invalid', $row, $dryRun, $invalidFields);
                    $summary['unresolved']++;

                    continue;
                }
                if ($syncId === '') {
                    if ($business) {
                        $this->reconcile(null, 'remote_create_pending_review', $row, $dryRun, [], ['payload_hash' => $this->payloadHash($this->normalizeRemote($row))]);
                        $summary['remote_create_pending_review']++;
                        $summary['unresolved']++;
                    }

                    continue;
                }
                if (! Str::isUuid($syncId) || ($counts[$syncId] ?? 0) > 1) {
                    $this->reconcile(null, Str::isUuid($syncId) ? 'duplicate_uuid' : 'invalid_uuid', $row, $dryRun, ['oasis_sync_id'], ['sync_id_hash' => $this->hash($syncId)]);
                    $summary['unresolved']++;

                    continue;
                }
                $record = DanaTalangan::withTrashed()->where('oasis_sync_id', $syncId)->first();
                if ($record === null) {
                    if (filled($row['oasis_deleted_at'])) {
                        $summary['ignored_tombstones']++;
                    } else {
                        $this->reconcile(null, 'unknown_remote_uuid', $row, $dryRun, [], ['sync_id_hash' => $this->hash($syncId)]);
                        $summary['unresolved']++;
                    }

                    continue;
                }
                $seen[$record->id] = true;
                if ($record->trashed()) {
                    if (blank($row['oasis_deleted_at'])) {
                        $this->reconcile($record, 'remote_row_for_deleted_local', $row, $dryRun);
                        $summary['unresolved']++;
                    } else {
                        $summary['ignored_tombstones']++;
                    }

                    continue;
                }
                if (filled($row['oasis_deleted_at'])) {
                    $this->reconcile($record, 'remote_tombstone', $row, $dryRun);
                    if (! $dryRun) {
                        $record->update(['sync_status' => 'conflict', 'last_sync_error' => null]);
                    }
                    $summary['unresolved']++;

                    continue;
                }

                $remotePayload = $this->normalizeRemote($row);
                $localPayload = $this->canonicalPayload($record);
                if ($this->baseline($record) !== null && $record->last_synced_field_hashes === null) {
                    if (! hash_equals($this->payloadHash($localPayload), $this->payloadHash($remotePayload))) {
                        $this->reconcile($record, 'baseline_missing', $row, $dryRun);
                        if (! $dryRun) {
                            $record->update(['sync_status' => 'conflict', 'last_sync_error' => null]);
                        }
                        $summary['unresolved']++;
                    } else {
                        if (! $dryRun) {
                            $this->recordBaseline($record, $remotePayload, $row['_row_number'], $actor);
                        }
                        $summary['unchanged']++;
                    }

                    continue;
                }
                if ($this->baseline($record) === null) {
                    if (! hash_equals($this->payloadHash($localPayload), $this->payloadHash($remotePayload))) {
                        $this->reconcile($record, 'baseline_missing', $row, $dryRun);
                        $summary['unresolved']++;
                    } else {
                        if (! $dryRun) {
                            $this->recordBaseline($record, $remotePayload, $row['_row_number'], $actor);
                        }
                        $summary['unchanged']++;
                    }

                    continue;
                }

                $decision = $this->threeWay($record, $localPayload, $remotePayload);
                if ($decision['owned_conflict'] || $decision['shared_conflicts'] !== []) {
                    $this->reconcile($record, 'remote_conflict', $row, $dryRun, [...$decision['owned_conflicts'], ...$decision['shared_conflicts']]);
                    $summary['unresolved']++;

                    continue;
                }
                $localNeedsPush = $decision['local_shared'] !== [] || $decision['local_owned'];
                if ($decision['remote_shared'] !== []) {
                    if (! $dryRun) {
                        $this->pullShared($record, $remotePayload, $decision['remote_shared'], $row['_row_number'], $actor);
                        $this->resolveIssues($record, $actor);
                    }
                    if ($localNeedsPush) {
                        if (! $dryRun) {
                            $record->update(['sync_status' => 'pending_update', 'last_sync_error' => null]);
                        }
                        $summary['pending_push']++;
                    } else {
                        $summary['updated']++;
                    }
                } elseif ($localNeedsPush) {
                    if (! $dryRun) {
                        $record->update(['sync_status' => 'pending_update', 'last_sync_error' => null]);
                    }
                    $summary['pending_push']++;
                } else {
                    if (! $dryRun) {
                        $this->recordBaseline($record, $remotePayload, $row['_row_number'], $actor, false);
                        $this->resolveIssues($record, $actor);
                    }
                    $summary['unchanged']++;
                }
            }

            DanaTalangan::query()
                ->where('remote_target_spreadsheet_id', $contract->spreadsheetId)
                ->whereNotNull('last_synced_at')
                ->get()
                ->each(function (DanaTalangan $record) use ($seen, $dryRun, &$summary): void {
                    if (! isset($seen[$record->id])) {
                        $this->reconcile($record, 'remote_missing', null, $dryRun);
                        if (! $dryRun) {
                            $record->update(['sync_status' => 'conflict', 'last_sync_error' => null]);
                        }
                        $summary['unresolved']++;
                    }
                });

            $outcome = $summary['unresolved'] === 0 && $summary['pending_push'] === 0 ? 'success' : 'partial_success';
            if ($status !== null) {
                $status->update([
                    'status' => $outcome,
                    'message' => $outcome === 'success' ? null : 'Perubahan Dana Talangan menunggu pengiriman atau tinjauan.',
                    'summary' => $summary,
                    'finished_at' => now(),
                    'last_successful_at' => now(),
                    'duration_ms' => $started->diffInMilliseconds(now()),
                ]);
            }

            return ['ok' => true, 'status' => $outcome, 'summary' => $summary];
        } catch (Throwable $exception) {
            report($exception);
            $status?->update(['status' => 'failed', 'message' => 'Sinkronisasi bridge Dana Talangan gagal.', 'summary' => $summary, 'finished_at' => now(), 'duration_ms' => $started->diffInMilliseconds(now())]);

            return ['ok' => false, 'status' => 'failed', 'message' => 'Sinkronisasi bridge Dana Talangan gagal.', 'summary' => $summary];
        }
    }

    public function approveRemoteCreate(DanaTalanganReconciliationItem $item, User $actor): DanaTalangan
    {
        if (! $actor->hasPermission('bridge_fund.manage_all') || $item->issue_code !== 'remote_create_pending_review' || $item->status !== DanaTalanganReconciliationStatus::Open) {
            throw new \DomainException('Item rekonsiliasi tidak dapat disetujui.');
        }
        if (! $this->modes->isPullEnabled()) {
            throw new \DomainException('Bridge Dana Talangan bidirectional tidak aktif.');
        }

        return $this->locks->runOrThrow($this->lockKey(), function () use ($item, $actor): DanaTalangan {
            $contract = $this->contracts->resolve();
            $row = collect($this->contracts->rows($contract))->firstWhere('_row_number', $item->remote_row_number);
            if ($row === null || filled($row['oasis_sync_id']) || filled($row['oasis_deleted_at'])) {
                throw new \DomainException('Baris remote berubah atau tidak tersedia.');
            }
            $payload = $this->normalizeRemote($row);
            if (! hash_equals((string) data_get($item->safe_metadata, 'payload_hash'), $this->payloadHash($payload))) {
                throw new \DomainException('Baris remote berubah sejak ditinjau.');
            }
            $projectName = $payload['Proyek'];
            $projects = LeadMaster::query()->where('is_active', true)->whereNotNull('branch_id')->get()
                ->filter(fn (LeadMaster $project) => $project->project_name === $projectName || $project->sheet_project_name === $projectName);
            if ($projects->count() !== 1) {
                throw new \DomainException('Proyek remote harus cocok tepat dengan satu proyek aktif.');
            }
            $project = $projects->first();
            $attributes = $this->attributesFromPayload($payload, $project, $actor);
            Validator::make($attributes, [
                'tanggal' => ['required', 'date'],
                'nama_konsumen' => ['required', 'string', 'max:255'],
                'kav' => ['nullable', 'string', 'max:100'],
                'project_name' => ['required', 'string', 'max:255'],
                'pinjam_nama' => ['boolean'],
                'pekerjaan' => ['nullable', 'string', 'max:255'],
                'status_perkawinan' => ['nullable', 'string', 'max:100'],
                'umur' => ['nullable', 'integer', 'min:0', 'max:150'],
                'nama_marketing' => ['nullable', 'string', 'max:255'],
                'tgl_komitmen' => ['nullable', 'date'],
                'penyelesaian' => ['nullable', 'string'],
                'konfirmasi_keuangan' => ['boolean'],
                'status' => ['required', 'in:sanggup,tidak_sanggup,lunas'],
            ])->validate();

            $syncId = (string) Str::uuid();
            try {
                $record = DB::transaction(function () use ($attributes, $syncId, $contract, $row, $payload, $item, $actor): DanaTalangan {
                    $lockedItem = DanaTalanganReconciliationItem::query()->whereKey($item->id)->where('status', 'open')->lockForUpdate()->firstOrFail();
                    $record = DanaTalangan::query()->create($attributes + [
                        'oasis_sync_id' => $syncId,
                        'sheet_name' => DanaTalanganSpreadsheetContract::SHEET,
                        'sheet_row_number' => $row['_row_number'],
                        'remote_target_spreadsheet_id' => $contract->spreadsheetId,
                        'sync_status' => 'synced',
                        'last_synced_payload_hash' => $this->payloadHash($payload),
                        'last_remote_payload_hash' => $this->payloadHash($payload),
                        'last_synced_field_hashes' => $this->fieldHashes($payload),
                        'last_synced_at' => now(),
                        'delivery_attempted_at' => now(),
                    ]);
                    $write = $this->writer->setSyncId($row['_row_number'], $syncId, false);
                    if ($write->rowNumber !== (int) $row['_row_number'] || $write->syncId !== $syncId || $this->payloadHash($this->normalizeRemote($write->rowValues)) !== $this->payloadHash($payload)) {
                        throw new \UnexpectedValueException('UUID remote Dana Talangan tidak dapat diverifikasi.');
                    }
                    $lockedItem->update(['status' => 'resolved', 'resolved_by' => $actor->id, 'resolved_at' => now(), 'dana_talangan_id' => $record->id, 'remote_sync_id' => $syncId]);
                    $this->audit($record, $actor, $row['_row_number'], $this->payloadHash($payload));

                    return $record;
                });
            } catch (Throwable $exception) {
                $this->reconcile(null, 'claim_failed', $row, false, [], ['payload_hash' => $this->payloadHash($payload)]);
                throw $exception;
            }

            return $record;
        });
    }

    public function tombstone(DanaTalangan $record, ?User $actor = null): void
    {
        if (! config('services.google_sheets.dana_talangan_bridge_enabled')) {
            throw new \DomainException('Bridge Dana Talangan sedang dinonaktifkan; data remote belum aman dihapus.');
        }

        $this->locks->runOrThrow($this->lockKey(), function () use ($record, $actor): void {
            $record = DanaTalangan::query()->findOrFail($record->id);
            if (! $record->oasis_sync_id || $record->remote_target_spreadsheet_id !== $this->modes->spreadsheetId()) {
                throw new \DomainException('Tujuan remote Dana Talangan tidak valid.');
            }
            $contract = $this->contracts->resolve();
            $matches = array_values(array_filter($this->contracts->rows($contract), fn (array $row) => $row['oasis_sync_id'] === $record->oasis_sync_id));
            if (count($matches) !== 1 || $this->baseline($record) === null || ! hash_equals((string) $record->last_remote_payload_hash, $this->payloadHash($this->normalizeRemote($matches[0])))) {
                $this->issue($record, 'tombstone_remote_conflict', $matches[0] ?? null);
                throw new \DomainException('Baris remote Dana Talangan tidak aman untuk dihapus.');
            }
            $this->writer->tombstone($record->oasis_sync_id, $actor?->id, false);
        });
    }

    public function canonicalPayload(DanaTalangan $record): array
    {
        return $this->normalizeRemote([
            'No' => $record->sheet_row_number ? max(1, $record->sheet_row_number - 1) : '',
            'Tanggal' => $record->tanggal?->format('Y-m-d'),
            'Nama Konsumen' => $record->nama_konsumen,
            'Kav' => $record->kav,
            'Proyek' => $record->project_name,
            'Pinjam Nama' => $record->pinjam_nama ? 'YA' : 'TIDAK',
            'Pekerjaan' => $record->pekerjaan,
            'Status Kawin' => $record->status_perkawinan,
            'Umur' => $record->umur,
            'Marketing' => $record->nama_marketing,
            'TGL Komitmen' => $record->tgl_komitmen?->format('Y-m-d'),
            'Penyelesaian' => $record->penyelesaian,
            'Konfirmasi' => $record->konfirmasi_keuangan ? 'YA' : 'TIDAK',
            'Status Cicilan' => $record->status,
        ]);
    }

    public function payloadHash(array $payload): string
    {
        return $this->hash(array_intersect_key($this->normalizeRemote($payload), array_flip(array_diff([...self::OWNED_FIELDS, ...self::SHARED_FIELDS], ['No']))));
    }

    private function threeWay(DanaTalangan $record, array $local, array $remote): array
    {
        $baseline = $record->last_synced_field_hashes ?? [];
        $ownedConflicts = [];
        $localOwnedFields = [];
        foreach (self::OWNED_FIELDS as $field) {
            $base = $baseline[$field] ?? null;
            $localChanged = $base !== null && ! hash_equals($base, $this->hash($local[$field]));
            $remoteChanged = $base !== null && ! hash_equals($base, $this->hash($remote[$field]));
            if ($remoteChanged) {
                $ownedConflicts[] = $field;
            }
            if ($localChanged) {
                $localOwnedFields[] = $field;
            }
        }
        $localShared = [];
        $remoteShared = [];
        $sharedConflicts = [];
        foreach (self::SHARED_FIELDS as $field) {
            $base = $baseline[$field] ?? null;
            $localChanged = $base !== null && ! hash_equals($base, $this->hash($local[$field]));
            $remoteChanged = $base !== null && ! hash_equals($base, $this->hash($remote[$field]));
            if ($localChanged && $remoteChanged && ! hash_equals($this->hash($local[$field]), $this->hash($remote[$field]))) {
                $sharedConflicts[] = $field;
            } elseif ($localChanged && ! $remoteChanged) {
                $localShared[] = $field;
            } elseif ($remoteChanged && ! $localChanged) {
                $remoteShared[] = $field;
            }
        }

        return [
            'owned_conflict' => $ownedConflicts !== [],
            'owned_conflicts' => $ownedConflicts,
            'local_owned' => $localOwnedFields !== [],
            'local_owned_fields' => $localOwnedFields,
            'local_shared' => $localShared,
            'remote_shared' => $remoteShared,
            'shared_conflicts' => $sharedConflicts,
            'local_changed' => $localOwnedFields !== [] || $localShared !== [],
        ];
    }

    private function normalizeRemote(array $row): array
    {
        $payload = [];
        foreach ([...self::OWNED_FIELDS, ...self::SHARED_FIELDS] as $field) {
            $payload[$field] = trim((string) ($row[$field] ?? ''));
        }
        foreach (['Tanggal', 'TGL Komitmen'] as $field) {
            $date = $this->date($payload[$field]);
            $payload[$field] = $date?->format('Y-m-d') ?? '';
        }
        foreach (['Pinjam Nama', 'Konfirmasi'] as $field) {
            $payload[$field] = $this->boolean($payload[$field]) ? '1' : '0';
        }
        $payload['Status Cicilan'] = str_replace(' ', '_', mb_strtolower($payload['Status Cicilan']));
        $payload['Umur'] = $payload['Umur'] === '' ? '' : (string) ((int) $payload['Umur']);

        return $payload;
    }

    private function invalidRemoteFields(array $row): array
    {
        $invalid = [];
        foreach (['Tanggal', 'TGL Komitmen'] as $field) {
            if (filled($row[$field] ?? null) && $this->date((string) $row[$field]) === null) {
                $invalid[] = $field;
            }
        }
        foreach (['Pinjam Nama', 'Konfirmasi'] as $field) {
            if (filled($row[$field] ?? null) && ! in_array(mb_strtolower(trim((string) $row[$field])), ['1', '0', 'true', 'false', 'ya', 'tidak', 'iya', 'tidak', 'yes', 'no', 'y', 'n', '✓'], true)) {
                $invalid[] = $field;
            }
        }
        if (filled($row['Status Cicilan'] ?? null) && ! in_array(str_replace(' ', '_', mb_strtolower(trim((string) $row['Status Cicilan']))), ['sanggup', 'tidak_sanggup', 'lunas'], true)) {
            $invalid[] = 'Status Cicilan';
        }

        return $invalid;
    }

    private function toCells(array $payload): array
    {
        return array_map(fn (string $field) => $payload[$field] ?? '', [...self::OWNED_FIELDS, ...self::SHARED_FIELDS]);
    }

    private function afterWrite(DanaTalangan $record, array $remoteRow, array $sentPayload, ?User $actor): array
    {
        $remote = $this->normalizeRemote($remoteRow);
        $sentHash = $this->payloadHash($sentPayload);

        return DB::transaction(function () use ($record, $remoteRow, $remote, $sentHash, $actor): array {
            $locked = DanaTalangan::query()->lockForUpdate()->findOrFail($record->id);
            $unchanged = hash_equals($sentHash, $this->payloadHash($this->canonicalPayload($locked)));
            $hash = $this->payloadHash($remote);
            $locked->update([
                'sheet_name' => DanaTalanganSpreadsheetContract::SHEET,
                'sheet_row_number' => $remoteRow['_row_number'] ?? $locked->sheet_row_number,
                'sync_status' => $unchanged ? 'synced' : 'pending_update',
                'last_synced_payload_hash' => $hash,
                'last_remote_payload_hash' => $hash,
                'last_synced_field_hashes' => $this->fieldHashes($remote),
                'last_synced_at' => $unchanged ? now() : $locked->last_synced_at,
                'last_sync_error' => null,
            ]);
            $this->audit($locked, $actor, $remoteRow['_row_number'] ?? null, $hash);

            return ['ok' => true, 'status' => $unchanged ? 'synced' : 'pending_update'];
        });
    }

    private function pullShared(DanaTalangan $record, array $payload, array $fields, int $rowNumber, ?User $actor): void
    {
        DB::transaction(function () use ($record, $payload, $fields, $rowNumber, $actor): void {
            $locked = DanaTalangan::query()->lockForUpdate()->findOrFail($record->id);
            $attributes = [];
            $mapping = [
                'TGL Komitmen' => ['tgl_komitmen', $this->date($payload['TGL Komitmen'])?->format('Y-m-d')],
                'Penyelesaian' => ['penyelesaian', $payload['Penyelesaian'] ?: null],
                'Konfirmasi' => ['konfirmasi_keuangan', $this->boolean($payload['Konfirmasi'])],
                'Status Cicilan' => ['status', $payload['Status Cicilan']],
            ];
            foreach ($fields as $field) {
                [$attribute, $value] = $mapping[$field];
                $attributes[$attribute] = $value;
            }
            $hash = $this->payloadHash($payload);
            $locked->update($attributes + [
                'sheet_name' => DanaTalanganSpreadsheetContract::SHEET,
                'sheet_row_number' => $rowNumber,
                'sync_status' => 'synced',
                'last_synced_payload_hash' => $hash,
                'last_remote_payload_hash' => $hash,
                'last_synced_field_hashes' => $this->fieldHashes($payload),
                'last_synced_at' => now(),
                'last_sync_error' => null,
                'updated_by' => null,
            ]);
            $this->audit($locked, $actor, $rowNumber, $hash);
        });
    }

    private function recordBaseline(DanaTalangan $record, array $payload, int $rowNumber, ?User $actor, bool $audit = true): void
    {
        $hash = $this->payloadHash($payload);
        $record->update([
            'sheet_name' => DanaTalanganSpreadsheetContract::SHEET,
            'sheet_row_number' => $rowNumber,
            'remote_target_spreadsheet_id' => $this->modes->spreadsheetId(),
            'sync_status' => 'synced',
            'last_synced_payload_hash' => $hash,
            'last_remote_payload_hash' => $hash,
            'last_synced_field_hashes' => $this->fieldHashes($payload),
            'last_synced_at' => now(),
            'last_sync_error' => null,
        ]);
        if ($audit) {
            $this->audit($record, $actor, $rowNumber, $hash);
        }
    }

    private function attributesFromPayload(array $payload, LeadMaster $project, User $actor): array
    {
        return [
            'project_id' => $project->id,
            'branch_id' => $project->branch_id,
            'tanggal' => $this->date($payload['Tanggal'])?->format('Y-m-d'),
            'nama_konsumen' => $payload['Nama Konsumen'],
            'kav' => $payload['Kav'] ?: null,
            'project_name' => $project->project_name,
            'pinjam_nama' => $this->boolean($payload['Pinjam Nama']),
            'pekerjaan' => $payload['Pekerjaan'] ?: null,
            'status_perkawinan' => $payload['Status Kawin'] ?: null,
            'umur' => $payload['Umur'] === '' ? null : (int) $payload['Umur'],
            'nama_marketing' => $payload['Marketing'] ?: null,
            'tgl_komitmen' => $this->date($payload['TGL Komitmen'])?->format('Y-m-d'),
            'penyelesaian' => $payload['Penyelesaian'] ?: null,
            'konfirmasi_keuangan' => $this->boolean($payload['Konfirmasi']),
            'status' => $payload['Status Cicilan'],
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ];
    }

    private function reconcile(?DanaTalangan $record, string $code, ?array $row, bool $dryRun, array $fields = [], array $metadata = []): void
    {
        if (! $dryRun) {
            $this->issue($record, $code, $row, $fields, $metadata);
        }
    }

    private function issue(?DanaTalangan $record, string $code, ?array $row, array $fields = [], array $metadata = []): void
    {
        $spreadsheetId = $this->modes->spreadsheetId();
        $rowNumber = $row['_row_number'] ?? null;
        $remoteSyncId = filled($row['oasis_sync_id'] ?? null) && Str::isUuid($row['oasis_sync_id']) ? $row['oasis_sync_id'] : null;
        $identity = $this->hash([$spreadsheetId, $record?->id, $rowNumber, $code]);
        DanaTalanganReconciliationItem::query()->updateOrCreate(
            ['identity_key' => $identity],
            [
                'dana_talangan_id' => $record?->id,
                'spreadsheet_id' => $spreadsheetId,
                'remote_sync_id' => $remoteSyncId,
                'remote_row_number' => $rowNumber,
                'issue_code' => $code,
                'field_names' => array_values(array_unique($fields)),
                'safe_metadata' => $metadata + ($row ? ['payload_hash' => $this->payloadHash($this->normalizeRemote($row))] : []),
                'status' => 'open',
                'resolved_by' => null,
                'resolved_at' => null,
            ],
        );
    }

    private function resolveIssues(DanaTalangan $record, ?User $actor): void
    {
        DanaTalanganReconciliationItem::query()->where('dana_talangan_id', $record->id)->where('status', 'open')->update([
            'status' => 'resolved',
            'resolved_by' => $actor?->id,
            'resolved_at' => now(),
        ]);
    }

    private function mark(DanaTalangan $record, string $status, ?string $error = null): array
    {
        $record->update(['sync_status' => $status, 'last_sync_error' => $error, 'delivery_attempted_at' => now()]);

        return ['ok' => false, 'status' => $status];
    }

    private function audit(DanaTalangan $record, ?User $actor, ?int $rowNumber, string $hash): void
    {
        ActivityLog::query()->create([
            'causer_id' => $actor?->id,
            'subject_type' => DanaTalangan::class,
            'subject_id' => $record->id,
            'event' => 'external_workbook',
            'description' => 'Dana Talangan diselaraskan dengan workbook eksternal.',
            'properties' => ['remote_row_number' => $rowNumber, 'payload_hash' => $hash],
        ]);
    }

    private function fieldHashes(array $payload): array
    {
        $payload = $this->normalizeRemote($payload);

        return collect(array_diff([...self::OWNED_FIELDS, ...self::SHARED_FIELDS], ['No']))->mapWithKeys(fn (string $field) => [$field => $this->hash($payload[$field])])->all();
    }

    private function baseline(DanaTalangan $record): ?string
    {
        return $record->last_remote_payload_hash ?: $record->last_synced_payload_hash;
    }

    private function date(string $value): ?CarbonImmutable
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, trim($value));
                if ($date && $date->format($format) === trim($value)) {
                    return $date;
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function boolean(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['1', 'true', 'ya', 'iya', 'yes', 'y', '✓'], true);
    }

    private function hash(string|array $value): string
    {
        return hash('sha256', is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $value);
    }

    private function lockKey(): string
    {
        return 'dana-talangan-bridge:spreadsheet:'.$this->modes->spreadsheetId().':Talangan';
    }

    private function emptySummary(): array
    {
        return ['updated' => 0, 'unchanged' => 0, 'pending_push' => 0, 'remote_create_pending_review' => 0, 'unresolved' => 0, 'ignored_tombstones' => 0];
    }
}
