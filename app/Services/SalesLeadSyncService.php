<?php

namespace App\Services;

use App\Enums\SalesLeadStatus;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\SalesLeadLifecycleReconciliationItem;
use App\Models\SalesLeadLifecycleSyncStatus;
use App\Models\SalesLeadStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SalesLeadSyncService
{
    /**
     * Buku Saku Sales lead-only synchronization.
     *
     * This is the service behind the standard Buku Saku Sales sync button and
     * the `sales-lead:sync` command. It reads, writes, and reconciles ONLY the
     * shared `lead` tab. Downstream lifecycle sheets (data_konsumen,
     * data_konsumen_nup, bi_checking, akad, data_sales, data_ceklok) are never
     * read or required here, and downstream reconciliation never influences the
     * health reported for the normal Buku Saku Sales sync.
     */
    public const SCOPE_BRANCH = 'lead:branch';

    public const SCOPE_USER_PREFIX = 'lead:user:';

    public static function branchScope(): string
    {
        return self::SCOPE_BRANCH;
    }

    public static function userScope(int $userId): string
    {
        return self::SCOPE_USER_PREFIX.$userId;
    }

    public static function scopeFor(?User $actor): string
    {
        return $actor !== null && $actor->isSales() ? self::userScope($actor->id) : self::branchScope();
    }

    private const REQUIRED_HEADERS = ['id_lead', 'tanggal_lead', 'nama_konsumen', 'proyek', 'sales_pic', 'status_lead', 'sumber_lead', 'kanal_masuk', 'aktivitas_lead'];

    public function __construct(
        private readonly GoogleSheetsApiService $googleSheets,
        private readonly SyncLockService $locks,
        private readonly PhoneNormalizationService $phones,
        private readonly SalesSheetIdentityService $sheetIdentities,
    ) {}

    public function sync(Branch $branch, ?User $actor = null): array
    {
        $scope = self::scopeFor($actor);

        // Same lock as the full lifecycle run so lead rows are never imported concurrently.
        $result = $this->locks->run('sales-lead-lifecycle:branch:'.$branch->id, function () use ($branch, $actor, $scope): array {
            $operationUuid = (string) Str::uuid();
            $status = SalesLeadLifecycleSyncStatus::query()->updateOrCreate(
                ['branch_id' => $branch->id, 'scope' => $scope],
                ['status' => 'syncing', 'operation_uuid' => $operationUuid, 'message' => null, 'summary' => null, 'started_at' => now(), 'finished_at' => null, 'duration_ms' => null, 'initiated_by' => $actor?->id],
            );

            try {
                if (! $branch->is_active) {
                    throw new \DomainException('Cabang tidak aktif.');
                }
                $spreadsheetId = trim((string) $branch->sheet_id);
                if ($spreadsheetId === '') {
                    throw new \DomainException('Spreadsheet cabang belum dikonfigurasi.');
                }

                $leadRows = $this->readLead($spreadsheetId);
                $summary = DB::transaction(fn (): array => $this->reconcileBranch($branch, $leadRows, $operationUuid, $actor));

                $outcome = $summary['unresolved'] === 0
                    ? 'success'
                    : 'partial_success';
                $finishedAt = now();
                $status->update([
                    'status' => $outcome,
                    'message' => $outcome === 'success' ? 'Sinkronisasi lead selesai.' : 'Sinkronisasi lead selesai dengan beberapa baris lead perlu diperiksa.',
                    'summary' => $summary,
                    'finished_at' => $finishedAt,
                    'duration_ms' => $status->started_at?->diffInMilliseconds($finishedAt),
                    'last_successful_at' => $finishedAt,
                ]);

                return ['ok' => true, 'status' => $outcome, 'branch' => $branch->name, 'message' => $status->message, 'summary' => $summary];
            } catch (\DomainException $exception) {
                $finishedAt = now();
                $status->update(['status' => 'failed', 'message' => $exception->getMessage(), 'finished_at' => $finishedAt, 'duration_ms' => $status->started_at?->diffInMilliseconds($finishedAt)]);

                return ['ok' => false, 'status' => 'failed', 'branch' => $branch->name, 'message' => $exception->getMessage(), 'summary' => []];
            } catch (Throwable $exception) {
                report($exception);
                $message = 'Sinkronisasi lead gagal. Periksa koneksi dan konfigurasi spreadsheet cabang.';
                $finishedAt = now();
                $status->update(['status' => 'failed', 'message' => $message, 'finished_at' => $finishedAt, 'duration_ms' => $status->started_at?->diffInMilliseconds($finishedAt)]);

                return ['ok' => false, 'status' => 'failed', 'branch' => $branch->name, 'message' => $message, 'summary' => []];
            }
        });

        return ['branch' => $branch->name] + $result;
    }

    public function syncAll(?int $branchId = null): array
    {
        return Branch::query()
            ->where('is_active', true)
            ->whereNotNull('sheet_id')
            ->where('sheet_id', '!=', '')
            ->when($branchId, fn ($query) => $query->whereKey($branchId))
            ->orderBy('id')
            ->get()
            ->map(fn (Branch $branch) => $this->sync($branch))
            ->all();
    }

    private function readLead(string $spreadsheetId): array
    {
        $titles = $this->googleSheets->sheetTitles($spreadsheetId);
        if (! in_array('lead', $titles, true)) {
            throw new \DomainException('Tab wajib lead tidak ditemukan pada spreadsheet cabang.');
        }

        $raw = $this->googleSheets->batchGetRaw($spreadsheetId, [$this->googleSheets->quoteSheetName('lead').'!A:ZZ'], 'FORMATTED_VALUE');
        $values = $raw['lead'] ?? [];
        $headers = array_map(fn ($value) => trim((string) $value), $values[0] ?? []);
        $headers = array_map(fn (string $header) => match ($header) {
            'sumber' => 'sumber_lead',
            'kanal' => 'kanal_masuk',
            'campaign' => 'aktivitas_lead',
            default => $header,
        }, $headers);
        $seen = [];
        foreach ($headers as $index => $header) {
            if ($header === '' || isset($seen[$header])) {
                $headers[$index] = '';
            }
            $seen[$header] = true;
        }
        $missing = array_values(array_diff(self::REQUIRED_HEADERS, $headers));
        $duplicates = array_keys(array_filter(array_count_values(array_filter($headers)), fn (int $count) => $count > 1));
        if ($missing !== [] || $duplicates !== []) {
            throw new \DomainException('Header wajib tab lead tidak valid: '.implode(', ', [...$missing, ...$duplicates]).'.');
        }

        return $this->rows($headers, array_slice($values, 1));
    }

    private function rows(array $headers, array $values): array
    {
        $rows = [];
        foreach ($values as $offset => $cells) {
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

        return $rows;
    }

    private function reconcileBranch(Branch $branch, array $rows, string $operationUuid, ?User $actor): array
    {
        // Personal Sales sync processes ONLY rows whose sales_pic belongs to the actor.
        // Unrelated Sales rows (and rows with unknown sales) are excluded entirely:
        // they are never imported, never reconciled, and never count toward a
        // Sales' personal summary. Historical/unmapped Sales problems remain
        // admin/branch reconciliation concerns handled by the branch sync.
        $actorSales = $actor !== null && $actor->isSales() ? $actor : null;
        if ($actorSales !== null) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => $this->sheetIdentities->spreadsheetValueEqualsPersonalValue($branch, $actorSales, (string) ($row['sales_pic'] ?? '')),
            ));
        }

        $resolvedQuery = SalesLeadLifecycleReconciliationItem::query()
            ->where('branch_id', $branch->id)
            ->where('status', 'open')
            ->whereIn('entity_type', ['lead', 'lead_status']);
        if ($actorSales !== null) {
            // A personal sync resolves only items attributable to the actor's own
            // leads. Un-attributable branch/admin items (for example sales_not_found
            // for unknown historical Sales) are left untouched.
            $resolvedQuery->whereHas('salesLead', fn ($query) => $query->where('sales_user_id', $actorSales->id));
        }
        $resolvedQuery->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $actorSales?->id]);

        $summary = ['imported' => 0, 'updated' => 0, 'linked' => 0, 'unresolved' => 0, 'ignored_deleted' => 0, 'capabilities' => ['lead' => true]];
        $remoteLeadRows = collect($rows);
        $summary['ignored_deleted'] = $remoteLeadRows->filter(fn (array $row) => filled($row['oasis_deleted_at'] ?? null))->count();
        $activeLeadRows = $remoteLeadRows->reject(fn (array $row) => filled($row['oasis_deleted_at'] ?? null))->values();
        $duplicateIds = $activeLeadRows->groupBy('id_lead')->filter(fn ($rows, $id) => $id !== '' && $rows->count() > 1)->keys()->all();

        foreach ($activeLeadRows as $row) {
            $idLead = $row['id_lead'] ?? '';
            $identity = $this->rowIdentity('lead', $row);
            if ($idLead === '' || in_array($idLead, $duplicateIds, true)) {
                $this->issue($branch, null, 'lead', $identity, $idLead === '' ? 'lead_id_missing' : 'lead_id_ambiguous', ['id_lead' => $idLead], $operationUuid, $summary);

                continue;
            }

            $syncLead = filled($row['oasis_sync_id'] ?? null)
                ? SalesLead::query()->where('branch_id', $branch->id)->where('external_sync_id', $row['oasis_sync_id'])->first()
                : null;
            $externalLead = SalesLead::query()->where('branch_id', $branch->id)->where('external_lead_id', $idLead)->first();
            if ($syncLead && $externalLead && ! $syncLead->is($externalLead)) {
                $this->issue($branch, null, 'lead', $identity, 'lead_identity_conflict', ['id_lead' => $idLead], $operationUuid, $summary);

                continue;
            }
            $lead = $syncLead ?? $externalLead;
            [$project, $projectIssue] = $this->uniqueProject($branch, $row['proyek'] ?? '');
            if ($projectIssue) {
                $this->issue($branch, $lead, 'lead', $identity, $projectIssue, ['project_name' => $row['proyek'] ?? ''], $operationUuid, $summary);

                continue;
            }
            [$sales, $salesIssue] = $this->uniqueAssignedSales($project, $row['sales_pic'] ?? '');
            if ($salesIssue) {
                $this->issue($branch, $lead, 'lead', $identity, $salesIssue, ['sales_name' => $row['sales_pic'] ?? ''], $operationUuid, $summary);

                continue;
            }
            $leadDate = $this->date($row['tanggal_lead'] ?? '');
            if (! $leadDate || blank($row['nama_konsumen'] ?? null)) {
                $this->issue($branch, $lead, 'lead', $identity, 'lead_data_invalid', ['remote_row_number' => $row['_row_number']], $operationUuid, $summary);

                continue;
            }

            $attributes = [
                'external_lead_id' => $idLead,
                'external_sync_id' => filled($row['oasis_sync_id'] ?? null) ? $row['oasis_sync_id'] : $lead?->external_sync_id,
                'id_promo' => $row['id_promo'] ?? null,
                'lead_date' => $leadDate,
                'customer_name' => $row['nama_konsumen'],
                'phone' => $row['no_hp'] ?? null,
                'normalized_phone' => $this->phones->normalize($row['no_hp'] ?? null),
                'source' => $row['sumber_lead'] ?? null,
                'platform' => $row['kanal_masuk'] ?? null,
                'campaign_name' => $row['aktivitas_lead'] ?? null,
                'notes' => $row['keterangan'] ?? null,
            ];
            if ($lead === null) {
                $lead = SalesLead::query()->create($attributes + [
                    'branch_id' => $branch->id,
                    'project_id' => $project->id,
                    'sales_user_id' => $sales->id,
                    'source_name_snapshot' => $attributes['source'],
                    'current_status' => SalesLeadStatus::NoResponse,
                    'created_by' => $actor?->id,
                    'updated_by' => $actor?->id,
                ]);
                $summary['imported']++;
            } else {
                if ((int) $lead->project_id !== (int) $project->id || (int) $lead->sales_user_id !== (int) $sales->id) {
                    $this->issue($branch, $lead, 'lead', $identity, 'lead_assignment_conflict', [], $operationUuid, $summary);

                    continue;
                }
                $lead->update($attributes + ['updated_by' => $actor?->id]);
                $summary['updated']++;
            }

            $this->reconcileLeadStatus($branch, $lead->fresh(), $row, $operationUuid, $actor, $summary);
        }

        return $summary;
    }

    private function reconcileLeadStatus(Branch $branch, SalesLead $lead, array $row, string $operationUuid, ?User $actor, array &$summary): void
    {
        $raw = trim((string) ($row['status_lead'] ?? ''));
        $normalized = mb_strtolower($raw);
        // `freelance` is an independent conversion flag, not a lifecycle current_status.
        if (in_array($normalized, ['jadi freelance', 'freelance'], true)) {
            return;
        }
        $target = match ($normalized) {
            '', 'no respon', 'no response' => SalesLeadStatus::NoResponse,
            'diskusi' => SalesLeadStatus::Discussion,
            'cek lokasi' => SalesLeadStatus::SiteVisit,
            'utj' => SalesLeadStatus::Utj,
            'cek silk', 'cek slik' => SalesLeadStatus::SlikCheck,
            'tidak lolos bi checking' => SalesLeadStatus::SlikRejected,
            'akad' => SalesLeadStatus::Akad,
            default => null,
        };
        if (! $target) {
            $this->issue($branch, $lead, 'lead_status', $this->rowIdentity('lead', $row), 'status_unknown', ['remote_status' => $raw], $operationUuid, $summary);

            return;
        }

        // The shared `lead` tab is authoritative for Buku Saku Sales. Lead-only sync
        // does not gate statuses on downstream lifecycle evidence.
        $this->applyStatus($lead, $target, 'lead_sheet_sync', $this->stableSourceId('lead', $row), now(), $actor);
    }

    private function applyStatus(SalesLead $lead, SalesLeadStatus $target, string $source, string $sourceId, $changedAt, ?User $actor): void
    {
        $current = $lead->current_status instanceof SalesLeadStatus ? $lead->current_status : SalesLeadStatus::fromInput($lead->current_status ?? 'no_response');
        if (($target->precedence() ?? -1) > ($current->precedence() ?? -1)) {
            $lead->update([
                'current_status' => $target,
                'current_status_changed_at' => $changedAt,
                'current_status_source' => $source,
                'current_status_source_id' => $sourceId,
                'updated_by' => $actor?->id,
            ]);
        }
        $this->recordHistory($lead, $target, $source, $sourceId, $changedAt, $actor);
    }

    private function recordHistory(SalesLead $lead, SalesLeadStatus $status, string $source, string $sourceId, $changedAt, ?User $actor): void
    {
        SalesLeadStatusHistory::query()->firstOrCreate(
            ['sales_lead_id' => $lead->id, 'source' => $source, 'source_id' => $sourceId, 'status' => $status->value],
            ['branch_id' => $lead->branch_id, 'actor_id' => $actor?->id, 'changed_at' => $changedAt],
        );
    }

    private function uniqueProject(Branch $branch, string $name): array
    {
        $projects = LeadMaster::query()->where('branch_id', $branch->id)->where('is_active', true)->get();
        $normalize = fn (?string $value) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '');
        $mapped = $projects->filter(fn (LeadMaster $project) => filled($project->sheet_project_name) && $normalize($project->sheet_project_name) === $normalize($name))->values();
        $projects = $mapped->isNotEmpty()
            ? $mapped
            : $projects->filter(fn (LeadMaster $project) => $normalize($project->project_name) === $normalize($name))->values();

        return $projects->count() === 1 ? [$projects->first(), null] : [null, $projects->isEmpty() ? 'project_not_found' : 'project_ambiguous'];
    }

    private function uniqueAssignedSales(LeadMaster $project, string $name): array
    {
        return $this->sheetIdentities->reverseSales($project->branch, $project, $name);
    }

    private function issue(Branch $branch, ?SalesLead $lead, string $entityType, string $identity, string $code, array $metadata, string $operationUuid, array &$summary): void
    {
        SalesLeadLifecycleReconciliationItem::query()->updateOrCreate(
            ['branch_id' => $branch->id, 'entity_type' => $entityType, 'identity_key' => $identity, 'issue_code' => $code],
            ['sales_lead_id' => $lead?->id, 'operation_uuid' => $operationUuid, 'status' => 'open', 'metadata' => $metadata, 'resolved_by' => null, 'resolved_at' => null],
        );
        $summary['unresolved']++;
    }

    private function rowIdentity(string $sheet, array $row): string
    {
        return $this->stableSourceId($sheet, $row);
    }

    private function stableSourceId(string $sheet, array $row): string
    {
        if (filled($row['oasis_sync_id'] ?? null)) {
            return $row['oasis_sync_id'];
        }

        if (filled($row['id_lead'] ?? null)) {
            return $sheet.':'.$row['id_lead'];
        }

        return $sheet.':unresolved:'.hash('sha256', json_encode(
            collect($row)->except('_row_number')->all(),
            JSON_THROW_ON_ERROR,
        ));
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
}
