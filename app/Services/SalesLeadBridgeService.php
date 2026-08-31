<?php

namespace App\Services;

use App\Enums\SalesLeadStatus;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\SalesLeadBridgeSetting;
use App\Models\SalesLeadLifecycleReconciliationItem;
use App\Models\SalesLeadLifecycleSyncStatus;
use App\Models\SalesLeadStatusHistory;
use App\Models\User;
use App\ValueObjects\SalesLeadSpreadsheetWriteResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SalesLeadBridgeService
{
    public const SCOPE = 'lead';

    private const SHEET = 'lead';

    private const SHARED_FIELDS = [
        'tanggal_lead' => 'lead_date',
        'nama_promo' => 'id_promo',
        'sumber_lead' => 'source',
        'kanal_masuk' => 'platform',
        'aktivitas_lead' => 'campaign_name',
        'nama_konsumen' => 'customer_name',
        'no_hp' => 'phone',
        'keterangan' => 'notes',
    ];

    private const PAYLOAD_FIELDS = [
        'id_lead', 'nama_promo', 'tanggal_lead', 'sumber_lead', 'kanal_masuk', 'aktivitas_lead',
        'nama_konsumen', 'no_hp', 'proyek', 'sales_pic', 'status_lead', 'keterangan',
    ];

    public const BRIDGE_ISSUES = [
        'invalid_uuid', 'duplicate_uuid', 'duplicate_id_lead', 'lead_id_missing', 'lead_identity_conflict',
        'orphan_remote_uuid', 'baseline_missing', 'lead_remote_conflict', 'remote_tombstone_conflict',
        'tombstone_remote_conflict', 'claim_unverified', 'claim_failed',
    ];

    public function __construct(
        private readonly SalesLeadBridgeModeService $modes,
        private readonly GoogleSheetsApiService $googleSheets,
        private readonly SalesLeadSpreadsheetContract $contracts,
        private readonly SalesLeadSpreadsheetWriter $writer,
        private readonly SalesSheetIdentityService $identities,
        private readonly SyncLockService $locks,
        private readonly PhoneNormalizationService $phones,
    ) {}

    public function push(SalesLead $lead, ?User $actor = null, string $operation = 'upsert'): array
    {
        $lead->loadMissing(['branch', 'project', 'sales']);
        if (! $this->modes->isPushEnabled($lead->branch)) {
            return ['ok' => false, 'status' => 'disabled', 'operation' => $operation];
        }

        return $this->locks->runOrThrow('sales-lead-bridge:branch:'.$lead->branch_id.':lead', fn () => $this->pushUnlocked($lead, $actor, $operation));
    }

    private function pushUnlocked(SalesLead $lead, ?User $actor, string $operation): array
    {
        $lead = DB::transaction(function () use ($lead): SalesLead {
            $locked = SalesLead::query()->lockForUpdate()->findOrFail($lead->id);
            if ($locked->remote_target_branch_id !== null && (int) $locked->remote_target_branch_id !== (int) $locked->branch_id) {
                throw new \DomainException('Tujuan remote lead tidak sesuai dengan cabang saat ini.');
            }
            if ($locked->remote_target_branch_id === null) {
                $locked->update(['remote_target_branch_id' => $locked->branch_id, 'delivery_attempted_at' => now()]);
            }

            return $locked->fresh(['branch', 'project', 'sales']);
        });

        return (function () use ($lead, $actor, $operation): array {
            $contract = $this->contracts->resolve($lead, self::SHEET);
            $remote = $this->read($lead->branch, $contract);
            $rows = $remote['rows'];
            $syncId = (string) $lead->external_sync_id;
            if (! Str::isUuid($syncId)) {
                $this->issue($lead->branch, $lead, 'lead', 'local', ['sync_id_hash' => $this->hash($syncId)], 'invalid_uuid');
                $this->mark($lead, 'conflict');

                return ['ok' => false, 'status' => 'conflict', 'operation' => $operation];
            }
            $matches = array_values(array_filter($rows, fn (array $row) => ($row['oasis_sync_id'] ?? '') === $syncId));
            $sameExternalId = array_values(array_filter($rows, fn (array $row) => ($row['id_lead'] ?? '') !== '' && ($row['id_lead'] ?? '') === $lead->external_lead_id && ($row['oasis_sync_id'] ?? '') !== $syncId));
            if (count($matches) > 1 || count($sameExternalId) > 0) {
                $this->issue($lead->branch, $lead, 'lead', 'duplicate_uuid', ['sync_id_hash' => $this->hash($syncId)], 'lead_remote_conflict');
                $this->mark($lead, 'conflict');

                return ['ok' => false, 'status' => 'conflict', 'operation' => $operation];
            }
            $existing = $matches[0] ?? null;
            if ($existing !== null && filled($existing['id_lead'] ?? null) && filled($lead->external_lead_id) && $existing['id_lead'] !== $lead->external_lead_id) {
                $this->issue($lead->branch, $lead, 'lead', $syncId, $this->metadata($existing), 'lead_identity_conflict');
                $this->mark($lead, 'conflict');

                return ['ok' => false, 'status' => 'conflict', 'operation' => $operation];
            }
            if ($existing !== null && filled($existing['oasis_deleted_at'] ?? null)) {
                $this->issue($lead->branch, $lead, 'lead', $syncId, $this->metadata($existing), 'remote_tombstone_conflict');
                $this->mark($lead, 'conflict');

                return ['ok' => false, 'status' => 'conflict', 'operation' => $operation];
            }

            $fields = $this->writeFields($lead);
            $sentHash = $this->ownedPayloadHash($fields);
            if ($existing !== null && $this->baseline($lead) === null) {
                $remoteHash = $this->payloadHash($existing);
                if (! hash_equals($sentHash, $this->payloadHash($existing))) {
                    $this->issue($lead->branch, $lead, 'lead', $syncId, $this->metadata($existing), 'baseline_missing');
                    $this->mark($lead, 'conflict');

                    return ['ok' => false, 'status' => 'conflict', 'operation' => $operation];
                }
                $this->recordRemoteBaseline($lead, $existing, $actor, $sentHash);

                return ['ok' => true, 'status' => 'synced', 'operation' => $operation, 'row_number' => $existing['_row_number']];
            }
            if ($existing !== null && $this->remoteChanged($lead, $existing)) {
                $this->issue($lead->branch, $lead, 'lead', $syncId, $this->metadata($existing), 'lead_remote_conflict');
                $this->mark($lead, 'conflict');

                return ['ok' => false, 'status' => 'conflict', 'operation' => $operation];
            }

            try {
                $result = $existing === null
                    ? $this->writer->append($lead, self::SHEET, $fields, $syncId, false)
                    : $this->writer->updateBySyncId($lead, self::SHEET, $syncId, $fields, false);
            } catch (Throwable $exception) {
                report($exception);
                $this->mark($lead, 'sync_failed', 'Sinkronisasi spreadsheet gagal.');

                throw $exception;
            }

            $row = $result->rowValues ?: $this->rowAfterWrite($lead->branch, $contract, $result->rowNumber);
            $status = $this->recordAfterPush($lead, $row, $actor, $sentHash);

            return ['ok' => true, 'status' => $status, 'operation' => $operation, 'row_number' => $result->rowNumber];
        })();
    }

    public function tombstone(SalesLead $lead, ?User $actor = null): SalesLeadSpreadsheetWriteResult
    {
        $lead->loadMissing('branch');
        if (! config('services.google_sheets.sales_lead_sync_enabled')) {
            throw new \DomainException('Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.');
        }
        if (! $lead->external_sync_id || ($lead->remote_target_branch_id === null && $lead->delivery_attempted_at === null && $lead->last_synced_at === null)) {
            throw new \DomainException('Lead belum memiliki tujuan remote yang dapat dihapus.');
        }
        if ($lead->remote_target_branch_id !== null && (int) $lead->remote_target_branch_id !== (int) $lead->branch_id) {
            throw new \DomainException('Tujuan remote lead tidak sesuai dengan cabang saat ini.');
        }

        return $this->locks->runOrThrow('sales-lead-bridge:branch:'.$lead->branch_id.':lead', function () use ($lead, $actor): SalesLeadSpreadsheetWriteResult {
            $contract = $this->contracts->resolve($lead, self::SHEET);
            $rows = $this->read($lead->branch, $contract)['rows'];
            $matches = array_values(array_filter($rows, fn (array $row) => ($row['oasis_sync_id'] ?? '') === $lead->external_sync_id && blank($row['oasis_deleted_at'] ?? null)));
            if (count($matches) !== 1 || $this->baseline($lead) === null || $this->remoteChanged($lead, $matches[0])) {
                $this->issue($lead->branch, $lead, 'lead', (string) $lead->external_sync_id, count($matches) === 1 ? $this->metadata($matches[0]) : [], 'tombstone_remote_conflict');
                $this->mark($lead, 'conflict');
                throw new \DomainException('Baris remote lead tidak aman untuk dihapus.');
            }

            return $this->writer->tombstoneBySyncId($lead, $lead->external_sync_id, $actor?->id, false);
        });
    }

    public function pull(Branch $branch, ?User $actor = null, bool $dryRun = false): array
    {
        if (! $this->modes->isPullEnabled($branch)) {
            return ['ok' => false, 'status' => 'disabled', 'summary' => $this->emptySummary()];
        }

        $status = null;
        try {
            $result = $this->locks->run('sales-lead-bridge:branch:'.$branch->id.':lead', function () use ($branch, $actor, $dryRun, &$status): array {
                $operationUuid = (string) Str::uuid();
                $status = SalesLeadLifecycleSyncStatus::query()->updateOrCreate(
                    ['branch_id' => $branch->id, 'scope' => self::SCOPE],
                    ['status' => 'syncing', 'operation_uuid' => $operationUuid, 'message' => null, 'summary' => null, 'started_at' => now(), 'finished_at' => null, 'duration_ms' => null, 'initiated_by' => $actor?->id],
                );
                $contract = $this->contracts->resolveForBranch($branch, self::SHEET, false);
                $remote = $this->read($branch, $contract);
                $rows = $remote['rows'];
                $summary = $this->emptySummary();
                $active = array_values(array_filter($rows, fn (array $row) => blank($row['oasis_deleted_at'] ?? null)));
                $uuidCounts = array_count_values(array_filter(array_map(fn (array $row) => trim((string) ($row['oasis_sync_id'] ?? '')), $active)));
                $idCounts = array_count_values(array_filter(array_map(fn (array $row) => trim((string) ($row['id_lead'] ?? '')), $active)));

                foreach ($rows as $row) {
                    if (filled($row['oasis_deleted_at'] ?? null)) {
                        $summary['ignored_deleted']++;
                        if (filled($row['oasis_sync_id'] ?? null)) {
                            $lead = SalesLead::withTrashed()->where('branch_id', $branch->id)->where('external_sync_id', $row['oasis_sync_id'])->first();
                            if ($lead !== null && $lead->trashed()) {
                                $summary['tombstones']++;
                            } elseif ($lead !== null) {
                                $this->reconcile($branch, $lead, 'remote_tombstone_conflict', $row, $dryRun, []);
                                $summary['unresolved']++;
                            }
                        }

                        continue;
                    }

                    $syncId = trim((string) ($row['oasis_sync_id'] ?? ''));
                    $idLead = trim((string) ($row['id_lead'] ?? ''));
                    if ($syncId !== '' && (! Str::isUuid($syncId) || ($uuidCounts[$syncId] ?? 0) > 1)) {
                        $this->reconcile($branch, null, 'invalid_uuid', $row, $dryRun, ['sync_id_hash' => $this->hash($syncId)]);
                        $summary['unresolved']++;

                        continue;
                    }
                    if ($idLead === '') {
                        $this->reconcile($branch, null, 'lead_id_missing', $row, $dryRun, ['id_lead_hash' => $this->hash($idLead)]);
                        $summary['unresolved']++;

                        continue;
                    }
                    if (($idCounts[$idLead] ?? 0) > 1) {
                        $this->reconcile($branch, null, 'duplicate_id_lead', $row, $dryRun, ['id_lead_hash' => $this->hash($idLead)]);
                        $summary['unresolved']++;

                        continue;
                    }

                    $syncLead = $syncId !== ''
                        ? SalesLead::withTrashed()->where('branch_id', $branch->id)->where('external_sync_id', $syncId)->first()
                        : null;
                    $externalLead = SalesLead::withTrashed()->where('branch_id', $branch->id)->where('external_lead_id', $idLead)->first();
                    if ($syncLead !== null && $externalLead !== null && ! $syncLead->is($externalLead)) {
                        $this->reconcile($branch, null, 'lead_identity_conflict', $row, $dryRun, ['id_lead_hash' => $this->hash($idLead)]);
                        $summary['unresolved']++;

                        continue;
                    }
                    if ($syncId !== '' && $syncLead === null && $externalLead !== null) {
                        $this->reconcile($branch, $externalLead, 'orphan_remote_uuid', $row, $dryRun, ['id_lead_hash' => $this->hash($idLead)]);
                        $summary['unresolved']++;

                        continue;
                    }
                    $lead = $syncLead ?? $externalLead;
                    if ($lead?->trashed()) {
                        $this->reconcile($branch, $lead, 'remote_row_for_deleted_lead', $row, $dryRun, []);
                        $summary['unresolved']++;

                        continue;
                    }
                    if ($lead !== null && $syncId !== '' && $lead->external_lead_id !== null && $lead->external_lead_id !== $idLead) {
                        $this->reconcile($branch, $lead, 'lead_identity_conflict', $row, $dryRun, ['id_lead_hash' => $this->hash($idLead)]);
                        $summary['unresolved']++;

                        continue;
                    }

                    [$project, $projectIssue] = $this->project($branch, $contract, (string) ($row['proyek'] ?? ''));
                    [$sales, $salesIssue] = $project ? $this->identities->reverseSales($branch, $project, (string) ($row['sales_pic'] ?? '')) : [null, 'project_not_found'];
                    if ($projectIssue || $salesIssue) {
                        $this->reconcile($branch, $lead, $projectIssue ?: $salesIssue, $row, $dryRun, []);
                        $summary['unresolved']++;

                        continue;
                    }
                    if ($lead !== null && ((int) $lead->project_id !== (int) $project->id || (int) $lead->sales_user_id !== (int) $sales->id)) {
                        $this->reconcile($branch, $lead, 'remote_assignment_owned_by_oasis', $row, $dryRun, ['field_names' => ['proyek', 'sales_pic']]);
                        $summary['unresolved']++;

                        continue;
                    }
                    $date = $this->date((string) ($row['tanggal_lead'] ?? ''));
                    if ($date === null || blank($row['nama_konsumen'] ?? null)) {
                        $this->reconcile($branch, $lead, 'lead_data_invalid', $row, $dryRun, []);
                        $summary['unresolved']++;

                        continue;
                    }
                    $remoteStatus = $this->status((string) ($row['status_lead'] ?? ''));
                    $localStatus = $lead === null ? SalesLeadStatus::NoResponse : $this->effectiveStatus($lead);
                    if (($lead === null && $remoteStatus !== SalesLeadStatus::NoResponse)
                        || ($lead !== null && $this->baseline($lead) !== null && ($remoteStatus === null || $remoteStatus !== $localStatus))) {
                        $this->reconcile($branch, $lead, 'remote_status_owned_by_oasis', $row, $dryRun, ['field_names' => ['status_lead']]);
                        $summary['unresolved']++;

                        continue;
                    }
                    if ($lead !== null && $this->baseline($lead) !== null && $remoteStatus === SalesLeadStatus::Freelance && ! $lead->is_freelance) {
                        $this->reconcile($branch, $lead, 'freelance_link_unconfirmed', $row, $dryRun, ['field_names' => ['status_lead']]);
                        $summary['unresolved']++;

                        continue;
                    }

                    $payload = $this->normalizeRemote($row);
                    $remoteHash = $this->payloadHash($payload);
                    if ($lead !== null && $syncLead === null && $syncId === '') {
                        $compatible = $this->baseline($lead) === null
                            ? hash_equals($this->payloadHash($this->canonicalPayload($lead)), $remoteHash)
                            : ! $this->dirty($lead) && ! $this->remoteChanged($lead, $row);
                        if (! $compatible) {
                            $this->reconcile($branch, $lead, 'baseline_missing', $row, $dryRun, []);
                            $summary['unresolved']++;

                            continue;
                        }
                        if (! $dryRun) {
                            $syncId = (string) Str::uuid();
                            $lead->update(['external_sync_id' => $syncId]);
                            $this->writer->setSyncIdByRow($lead, (int) $row['_row_number'], $syncId, false);
                        }
                    }
                    if ($lead === null) {
                        if ($dryRun) {
                            $summary['claimable']++;

                            continue;
                        }
                        try {
                            $claimSyncId = $syncId !== '' ? $syncId : (string) Str::uuid();
                            $lead = DB::transaction(function () use ($branch, $project, $sales, $idLead, $date, $payload, $actor, $remoteHash, $row, $contract, $claimSyncId): SalesLead {
                                $currentProject = LeadMaster::query()->whereKey($project->id)->where('branch_id', $branch->id)->where('is_active', true)->lockForUpdate()->first();
                                $assigned = $currentProject?->assignedUsers()->whereKey($sales->id)->where('users.is_active', true)->wherePivot('is_active', true)->exists() ?? false;
                                if ($currentProject === null || ! $assigned) {
                                    throw new \DomainException('Penugasan proyek atau Sales berubah saat klaim lead.');
                                }
                                $existing = SalesLead::withTrashed()->where('branch_id', $branch->id)->where('external_lead_id', $idLead)->lockForUpdate()->first();
                                if ($existing !== null) {
                                    throw new \DomainException('ID lead remote sudah diklaim pada cabang ini.');
                                }
                                $syncId = $claimSyncId;
                                $lead = SalesLead::create([
                                    'branch_id' => $branch->id,
                                    'remote_target_branch_id' => $branch->id,
                                    'project_id' => $currentProject->id,
                                    'sales_user_id' => $sales->id,
                                    'external_lead_id' => $idLead,
                                    'external_sync_id' => $syncId ?: (string) Str::uuid(),
                                    'lead_date' => $date,
                                    'id_promo' => $payload['nama_promo'],
                                    'source' => $payload['sumber_lead'],
                                    'source_name_snapshot' => $payload['sumber_lead'],
                                    'platform' => $payload['kanal_masuk'],
                                    'campaign_name' => $payload['aktivitas_lead'],
                                    'customer_name' => $payload['nama_konsumen'],
                                    'phone' => $payload['no_hp'],
                                    'normalized_phone' => $this->phones->normalize($payload['no_hp']),
                                    'notes' => $payload['keterangan'],
                                    'current_status' => SalesLeadStatus::NoResponse,
                                    'current_status_changed_at' => now(),
                                    'current_status_source' => 'external_workbook',
                                    'sync_status' => 'synced',
                                    'last_remote_payload_hash' => $remoteHash,
                                    'last_synced_payload_hash' => $remoteHash,
                                    'last_synced_field_hashes' => $this->fieldHashes($payload),
                                    'last_synced_at' => now(),
                                    'delivery_attempted_at' => now(),
                                    'created_by' => $actor?->id,
                                    'updated_by' => $actor?->id,
                                ]);
                                SalesLeadStatusHistory::query()->create([
                                    'sales_lead_id' => $lead->id,
                                    'branch_id' => $branch->id,
                                    'actor_id' => $actor?->id,
                                    'status' => SalesLeadStatus::NoResponse->value,
                                    'source' => 'external_workbook',
                                    'source_id' => $idLead,
                                    'changed_at' => now(),
                                    'operation_uuid' => $syncId,
                                ]);
                                $this->writer->setSyncIdByRow($lead, $row['_row_number'], $syncId, false);
                                $verified = $this->googleSheets->readLeadRows($contract->spreadsheetId)['rows'] ?? [];
                                $claimedRow = collect($verified)->firstWhere('_row_number', $row['_row_number']);
                                if (($claimedRow['oasis_sync_id'] ?? '') !== $syncId) {
                                    throw new \UnexpectedValueException('Klaim baris lead tidak dapat diverifikasi.');
                                }

                                return $lead;
                            });
                            $summary['claimed']++;
                        } catch (Throwable $exception) {
                            report($exception);
                            $claimed = SalesLead::withTrashed()
                                ->where('branch_id', $branch->id)
                                ->where('external_lead_id', $idLead)
                                ->first();
                            $code = $exception instanceof \UnexpectedValueException
                                ? 'claim_unverified'
                                : ($claimed !== null && $syncId !== '' ? 'orphan_remote_uuid' : ($claimed !== null ? 'lead_identity_conflict' : 'claim_failed'));
                            $this->reconcile($branch, $claimed, $code, $row, false, []);
                            $summary['unresolved']++;
                        }

                        continue;
                    }

                    $outcome = DB::transaction(function () use ($lead, $row, $payload, $remoteHash, $idLead, $actor, $dryRun): string {
                        $locked = SalesLead::query()->lockForUpdate()->findOrFail($lead->id);
                        if ($this->baseline($locked) === null) {
                            if (! hash_equals($this->payloadHash($this->canonicalPayload($locked)), $remoteHash)) {
                                return 'baseline_missing';
                            }
                            if (! $dryRun) {
                                $this->recordRemoteBaseline($locked, $row, $actor, $remoteHash);
                            }

                            return 'unchanged';
                        }
                        if ($this->dirty($locked) && $this->remoteChanged($locked, $row)) {
                            return 'lead_remote_conflict';
                        }
                        if (! $dryRun && $this->remoteChanged($locked, $row)) {
                            $locked->update($this->sharedAttributes($payload) + [
                                'external_lead_id' => $idLead,
                                'last_remote_payload_hash' => $remoteHash,
                                'last_synced_payload_hash' => $remoteHash,
                                'last_synced_field_hashes' => $this->fieldHashes($payload),
                                'sync_status' => 'synced',
                                'last_synced_at' => now(),
                                'last_sync_error' => null,
                                'updated_by' => $actor?->id,
                            ]);
                            $this->audit($locked, $actor, $row, $remoteHash);

                            return 'updated';
                        }

                        return 'unchanged';
                    });
                    if (in_array($outcome, ['baseline_missing', 'lead_remote_conflict'], true)) {
                        $this->reconcile($branch, $lead, $outcome, $row, $dryRun, []);
                        $summary['unresolved']++;
                    } else {
                        if (! $dryRun) {
                            $this->resolveBridgeIssues($branch, $lead, $actor);
                        }
                        $summary[$outcome]++;
                    }
                }

                return ['ok' => true, 'status' => $summary['unresolved'] === 0 ? 'success' : 'partial_success', 'branch' => $branch->name, 'message' => 'Sinkronisasi bridge lead selesai.', 'summary' => $summary];
            });
            $finishedAt = now();
            if ($status !== null && ($result['status'] ?? null) !== 'syncing') {
                $status->update([
                    'status' => $result['status'],
                    'message' => $result['message'],
                    'summary' => $result['summary'],
                    'finished_at' => $finishedAt,
                    'duration_ms' => $status?->started_at?->diffInMilliseconds($finishedAt),
                    'last_successful_at' => $result['ok'] ? $finishedAt : $status->last_successful_at,
                ]);
            }

            return ['branch' => $branch->name] + $result;
        } catch (Throwable $exception) {
            report($exception);
            $finishedAt = now();
            $status?->update(['status' => 'failed', 'message' => 'Sinkronisasi bridge lead gagal.', 'summary' => [], 'finished_at' => $finishedAt, 'duration_ms' => $status?->started_at?->diffInMilliseconds($finishedAt)]);

            return ['ok' => false, 'status' => 'failed', 'branch' => $branch->name, 'message' => 'Sinkronisasi bridge lead gagal.', 'summary' => []];
        }
    }

    public function preflight(Branch $branch): SalesLeadBridgeSetting
    {
        try {
            $contract = $this->contracts->resolveForBranch($branch, self::SHEET, false);
            $hash = $this->hash([
                'spreadsheet_id' => $contract->spreadsheetId,
                'headers' => $contract->headers,
                'resolved_headers' => $contract->resolvedHeaders,
                'formula_headers' => $contract->formulaOwnedHeaders,
                'validation_options' => $contract->validationOptions,
                'metadata_headers' => SalesLeadSpreadsheetContract::META_HEADERS,
                'column_metadata' => $contract->columnMetadata,
            ]);

            return SalesLeadBridgeSetting::query()->updateOrCreate(
                ['branch_id' => $branch->id],
                ['status' => 'success', 'last_preflight_at' => now(), 'last_preflight_hash' => $hash],
            );
        } catch (Throwable $exception) {
            report($exception);
            SalesLeadBridgeSetting::query()->updateOrCreate(
                ['branch_id' => $branch->id],
                ['mode' => 'off', 'status' => 'failed', 'last_preflight_at' => now(), 'last_preflight_hash' => null],
            );

            throw new \DomainException('Preflight bridge lead gagal.');
        }
    }

    public function canonicalPayload(SalesLead $lead): array
    {
        $lead->loadMissing(['project', 'sales']);

        return $this->normalizeRemote([
            'id_lead' => $lead->external_lead_id,
            'nama_promo' => $lead->id_promo,
            'tanggal_lead' => $lead->lead_date?->format('Y-m-d'),
            'sumber_lead' => $lead->effective_source,
            'kanal_masuk' => $lead->platform,
            'aktivitas_lead' => $lead->campaign_name ?: $lead->campaign_id,
            'nama_konsumen' => $lead->customer_name,
            'no_hp' => $lead->phone,
            'proyek' => $lead->project?->sheet_project_name ?: $lead->project?->project_name,
            'sales_pic' => $lead->sales?->name,
            'status_lead' => $this->effectiveStatus($lead)->spreadsheetValue(),
            'keterangan' => $lead->notes,
        ]);
    }

    public function payloadHash(array $payload): string
    {
        return $this->hash(array_intersect_key($this->normalizeRemote($payload), array_flip(self::PAYLOAD_FIELDS)));
    }

    private function ownedPayloadHash(array $payload): string
    {
        return $this->payloadHash(array_diff_key($payload, ['id_lead' => true]));
    }

    private function read(Branch $branch, object $contract): array
    {
        $remote = $this->googleSheets->readLeadRows($contract->spreadsheetId);
        $headers = $remote['headers'];
        $aliases = ['id_promo' => 'nama_promo', 'sumber' => 'sumber_lead', 'kanal' => 'kanal_masuk', 'campaign' => 'aktivitas_lead'];
        $rows = array_map(function (array $row) use ($aliases): array {
            foreach ($aliases as $from => $to) {
                if (! isset($row[$to]) && isset($row[$from])) {
                    $row[$to] = $row[$from];
                }
            }

            return $row;
        }, $remote['rows']);

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function writeFields(SalesLead $lead): array
    {
        return $this->canonicalPayload($lead);
    }

    private function rowAfterWrite(Branch $branch, object $contract, int $rowNumber): array
    {
        $remote = $this->read($branch, $contract);

        return collect($remote['rows'])->firstWhere('_row_number', $rowNumber) ?? [];
    }

    private function recordAfterPush(SalesLead $lead, array $row, ?User $actor, string $sentHash): string
    {
        return DB::transaction(function () use ($lead, $row, $actor, $sentHash): string {
            $locked = SalesLead::query()->lockForUpdate()->findOrFail($lead->id);
            $payload = $this->normalizeRemote($row);
            $remoteHash = $this->payloadHash($payload);
            $unchanged = hash_equals($sentHash, $this->ownedPayloadHash($this->canonicalPayload($locked)));
            $locked->update([
                'external_lead_id' => filled($payload['id_lead'] ?? null) ? $payload['id_lead'] : $locked->external_lead_id,
                'sync_status' => $unchanged ? 'synced' : 'pending_update',
                'last_synced_at' => $unchanged ? now() : $locked->last_synced_at,
                'last_remote_payload_hash' => $remoteHash,
                'last_synced_payload_hash' => $remoteHash,
                'last_synced_field_hashes' => $this->fieldHashes($payload),
                'delivery_attempted_at' => now(),
                'last_sync_error' => null,
            ]);
            $this->audit($locked, $actor, $row, $remoteHash);

            return $unchanged ? 'synced' : 'pending_update';
        });
    }

    private function recordRemoteBaseline(SalesLead $lead, array $row, ?User $actor, string $hash): void
    {
        $payload = $this->normalizeRemote($row);
        $lead->update([
            'external_lead_id' => filled($payload['id_lead'] ?? null) ? $payload['id_lead'] : $lead->external_lead_id,
            'sync_status' => 'synced',
            'last_synced_at' => now(),
            'last_remote_payload_hash' => $hash,
            'last_synced_payload_hash' => $hash,
            'last_synced_field_hashes' => $this->fieldHashes($payload),
            'delivery_attempted_at' => now(),
            'last_sync_error' => null,
        ]);
        $this->audit($lead, $actor, $row, $hash);
    }

    private function baseline(SalesLead $lead): ?string
    {
        return $lead->last_remote_payload_hash ?: $lead->last_synced_payload_hash;
    }

    private function remoteChanged(SalesLead $lead, array $row): bool
    {
        $baseline = $this->baseline($lead);

        return $baseline !== null && ! hash_equals($baseline, $this->payloadHash($row));
    }

    private function dirty(SalesLead $lead): bool
    {
        if (in_array($lead->sync_status, ['pending_create', 'pending_update', 'pending_delete', 'sync_failed', 'conflict'], true)) {
            return true;
        }
        if ($lead->last_synced_field_hashes === null) {
            return false;
        }

        return $lead->last_synced_field_hashes !== $this->fieldHashes($this->canonicalPayload($lead));
    }

    private function normalizeRemote(array $row): array
    {
        $payload = [];
        foreach (self::PAYLOAD_FIELDS as $field) {
            $payload[$field] = trim((string) ($row[$field] ?? ''));
        }
        $date = $this->date($payload['tanggal_lead']);
        if ($date !== null) {
            $payload['tanggal_lead'] = $date->format('Y-m-d');
        }
        $status = $this->status($payload['status_lead']);
        if ($status !== null) {
            $payload['status_lead'] = $status->value;
        }

        return $payload;
    }

    private function fieldHashes(array $payload): array
    {
        $hashes = [];
        foreach (self::SHARED_FIELDS as $remote => $local) {
            $hashes[$remote] = $this->hash((string) ($payload[$remote] ?? ''));
        }

        return $hashes;
    }

    private function sharedAttributes(array $payload): array
    {
        return [
            'lead_date' => $this->date($payload['tanggal_lead']),
            'id_promo' => $payload['nama_promo'],
            'source' => $payload['sumber_lead'],
            'platform' => $payload['kanal_masuk'],
            'campaign_name' => $payload['aktivitas_lead'],
            'customer_name' => $payload['nama_konsumen'],
            'phone' => $payload['no_hp'],
            'normalized_phone' => $this->phones->normalize($payload['no_hp']),
            'notes' => $payload['keterangan'],
        ];
    }

    private function project(Branch $branch, object $contract, string $value): array
    {
        $options = $contract->validationOptions['proyek'] ?? [];
        $exact = collect($options)->first(fn (string $option) => mb_strtolower(trim($option)) === mb_strtolower(trim($value)));
        $projects = LeadMaster::where('branch_id', $branch->id)->where('is_active', true)->get()->filter(fn (LeadMaster $project) => $exact !== null && in_array($exact, [$project->sheet_project_name, $project->project_name], true));

        return $projects->count() === 1 ? [$projects->first(), null] : [null, $projects->isEmpty() ? 'project_not_found' : 'project_ambiguous'];
    }

    private function status(string $value): ?SalesLeadStatus
    {
        return match (mb_strtolower(trim($value))) {
            '', 'no respon', 'no response' => SalesLeadStatus::NoResponse,
            'diskusi' => SalesLeadStatus::Discussion,
            'tatap muka' => SalesLeadStatus::FaceToFace,
            'cek lokasi' => SalesLeadStatus::SiteVisit,
            'utj' => SalesLeadStatus::Utj,
            'cek silk', 'cek slik' => SalesLeadStatus::SlikCheck,
            'tidak lolos bi checking' => SalesLeadStatus::SlikRejected,
            'akad' => SalesLeadStatus::Akad,
            'jadi freelance', 'freelance' => SalesLeadStatus::Freelance,
            default => null,
        };
    }

    private function effectiveStatus(SalesLead $lead): SalesLeadStatus
    {
        if ($lead->freelance_converted_at !== null && ($lead->current_status_changed_at === null || $lead->freelance_converted_at->gt($lead->current_status_changed_at))) {
            return SalesLeadStatus::Freelance;
        }

        return $lead->current_status instanceof SalesLeadStatus
            ? $lead->current_status
            : SalesLeadStatus::fromInput($lead->current_status ?? SalesLeadStatus::NoResponse->value);
    }

    private function date(string $value): ?CarbonImmutable
    {
        foreach (['Y-m-d', 'j-M-Y', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
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

    private function resolveBridgeIssues(Branch $branch, SalesLead $lead, ?User $actor): void
    {
        SalesLeadLifecycleReconciliationItem::query()
            ->where('branch_id', $branch->id)
            ->where('sales_lead_id', $lead->id)
            ->whereIn('issue_code', self::BRIDGE_ISSUES)
            ->where('status', 'open')
            ->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $actor?->id]);
    }

    private function reconcile(Branch $branch, ?SalesLead $lead, string $code, array $row, bool $dryRun, array $metadata): void
    {
        if ($dryRun) {
            return;
        }
        $this->issue($branch, $lead, 'lead', filled($row['oasis_sync_id'] ?? null) ? (string) $row['oasis_sync_id'] : 'row:'.$row['_row_number'], $metadata + ['remote_row_number' => $row['_row_number'], 'id_lead_hash' => $this->hash((string) ($row['id_lead'] ?? ''))], $code);
    }

    private function issue(Branch $branch, ?SalesLead $lead, string $entityType, string $identity, array $metadata, string $code = 'lead_remote_conflict'): void
    {
        SalesLeadLifecycleReconciliationItem::query()->updateOrCreate(
            ['branch_id' => $branch->id, 'entity_type' => $entityType, 'identity_key' => $identity, 'issue_code' => $code],
            ['sales_lead_id' => $lead?->id, 'status' => 'open', 'metadata' => $metadata],
        );
    }

    private function mark(SalesLead $lead, string $status, ?string $error = null): void
    {
        $lead->update(['sync_status' => $status, 'last_sync_error' => $error, 'delivery_attempted_at' => now()]);
    }

    private function audit(SalesLead $lead, ?User $actor, array $row, string $hash): void
    {
        ActivityLog::create([
            'causer_id' => $actor?->id,
            'subject_type' => SalesLead::class,
            'subject_id' => $lead->id,
            'event' => 'external_workbook',
            'description' => 'Lead diperbarui dari workbook eksternal.',
            'properties' => ['branch_id' => $lead->branch_id, 'remote_row_number' => $row['_row_number'] ?? null, 'payload_hash' => $hash],
        ]);
    }

    private function metadata(array $row): array
    {
        return ['remote_row_number' => $row['_row_number'] ?? null, 'payload_hash' => $this->payloadHash($row), 'id_lead_hash' => $this->hash((string) ($row['id_lead'] ?? ''))];
    }

    private function hash(string|array $value): string
    {
        return hash('sha256', is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $value);
    }

    private function emptySummary(): array
    {
        return ['claimed' => 0, 'updated' => 0, 'unchanged' => 0, 'claimable' => 0, 'unresolved' => 0, 'ignored_deleted' => 0, 'tombstones' => 0];
    }
}
