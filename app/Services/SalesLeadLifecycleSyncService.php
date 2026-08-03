<?php

namespace App\Services;

use App\Enums\SalesLeadStatus;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\SalesLeadAkadLink;
use App\Models\SalesLeadConsumerLink;
use App\Models\SalesLeadFreelanceLink;
use App\Models\SalesLeadLifecycleReconciliationItem;
use App\Models\SalesLeadLifecycleSyncStatus;
use App\Models\SalesLeadSiteVisit;
use App\Models\SalesLeadSlikAttempt;
use App\Models\SalesLeadStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SalesLeadLifecycleSyncService
{
    private const SHEETS = ['lead', 'data_konsumen', 'data_konsumen_nup', 'bi_checking', 'akad', 'data_sales', 'data_ceklok'];

    private const REQUIRED_HEADERS = [
        'lead' => ['id_lead', 'tanggal_lead', 'nama_konsumen', 'proyek', 'sales_pic', 'status_lead'],
        'data_konsumen' => ['id_kavling', 'no_ktp', 'nama_konsumen'],
        'data_konsumen_nup' => ['nup', 'no_ktp', 'nama_konsumen'],
        'bi_checking' => ['id_kavling', 'tanggal_slik', 'hasil_slik'],
        'akad' => ['id_kavling', 'tanggal_akad'],
        'data_sales' => ['nik_sales', 'nama_sales'],
        'data_ceklok' => ['nama_konsumen', 'tanggal_ceklok', 'status_ceklok'],
    ];

    public function __construct(
        private readonly GoogleSheetsApiService $googleSheets,
        private readonly SyncLockService $locks,
        private readonly PhoneNormalizationService $phones,
    ) {}

    public function sync(Branch $branch, ?User $actor = null): array
    {
        $result = $this->locks->run('sales-lead-lifecycle:branch:'.$branch->id, function () use ($branch, $actor): array {
            $operationUuid = (string) Str::uuid();
            $status = SalesLeadLifecycleSyncStatus::query()->updateOrCreate(
                ['branch_id' => $branch->id],
                ['status' => 'syncing', 'operation_uuid' => $operationUuid, 'message' => null, 'summary' => null, 'started_at' => now(), 'finished_at' => null, 'initiated_by' => $actor?->id],
            );

            try {
                if (! $branch->is_active) {
                    throw new \DomainException('Cabang tidak aktif.');
                }
                $spreadsheetId = trim((string) $branch->sheet_id);
                if ($spreadsheetId === '') {
                    throw new \DomainException('Spreadsheet cabang belum dikonfigurasi.');
                }

                // Complete the remote read before changing branch lifecycle records.
                [$sheets, $configurationIssues, $capabilities] = $this->readSpreadsheet($spreadsheetId);
                $summary = DB::transaction(fn (): array => $this->reconcileBranch(
                    $branch, $sheets, $configurationIssues, $capabilities, $operationUuid, $actor,
                ));

                $status->update([
                    'status' => 'success',
                    'message' => 'Sinkronisasi siklus lead selesai.',
                    'summary' => $summary,
                    'finished_at' => now(),
                    'last_successful_at' => now(),
                ]);

                return ['ok' => true, 'status' => 'success', 'branch' => $branch->name, 'message' => $status->message, 'summary' => $summary];
            } catch (Throwable $exception) {
                $status->update(['status' => 'failed', 'message' => $exception->getMessage(), 'finished_at' => now()]);

                return ['ok' => false, 'status' => 'failed', 'branch' => $branch->name, 'message' => $exception->getMessage(), 'summary' => []];
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

    private function readSpreadsheet(string $spreadsheetId): array
    {
        $titles = $this->googleSheets->sheetTitles($spreadsheetId);
        if (! in_array('lead', $titles, true)) {
            throw new \DomainException('Tab wajib lead tidak ditemukan pada spreadsheet cabang.');
        }

        $present = array_values(array_intersect(self::SHEETS, $titles));
        $ranges = array_map(fn (string $sheet) => $this->googleSheets->quoteSheetName($sheet).'!A:ZZ', $present);
        $rawSheets = $this->googleSheets->batchGetRaw($spreadsheetId, $ranges, 'FORMATTED_VALUE');
        $sheets = [];
        $issues = [];
        $capabilities = [];

        foreach (self::SHEETS as $sheet) {
            if (! in_array($sheet, $present, true)) {
                $capabilities[$sheet] = false;
                $issues[] = [$sheet, 'sheet_missing', ['sheet_name' => $sheet]];

                continue;
            }

            $values = $rawSheets[$sheet] ?? [];
            $headers = array_map(fn ($value) => trim((string) $value), $values[0] ?? []);
            $missing = array_values(array_diff(self::REQUIRED_HEADERS[$sheet], $headers));
            $duplicates = array_keys(array_filter(array_count_values(array_filter($headers)), fn (int $count) => $count > 1));
            if ($missing !== [] || $duplicates !== []) {
                if ($sheet === 'lead') {
                    throw new \DomainException('Header wajib tab lead tidak valid: '.implode(', ', [...$missing, ...$duplicates]).'.');
                }
                $capabilities[$sheet] = false;
                $issues[] = [$sheet, 'headers_invalid', ['sheet_name' => $sheet, 'missing_headers' => $missing, 'duplicate_headers' => $duplicates]];

                continue;
            }

            $capabilities[$sheet] = true;
            $sheets[$sheet] = $this->rows($headers, array_slice($values, 1));
        }

        return [$sheets, $issues, $capabilities];
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

    private function reconcileBranch(Branch $branch, array $sheets, array $configurationIssues, array $capabilities, string $operationUuid, ?User $actor): array
    {
        SalesLeadLifecycleReconciliationItem::query()
            ->where('branch_id', $branch->id)
            ->where('status', 'open')
            ->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $actor?->id]);

        $summary = ['imported' => 0, 'updated' => 0, 'linked' => 0, 'unresolved' => 0, 'capabilities' => $capabilities];
        foreach ($configurationIssues as [$identity, $code, $metadata]) {
            $this->issue($branch, null, 'capability', $identity, $code, $metadata, $operationUuid, $summary);
        }

        $leadRows = [];
        $duplicateIds = collect($sheets['lead'] ?? [])->groupBy('id_lead')->filter(fn ($rows, $id) => $id !== '' && $rows->count() > 1)->keys()->all();
        foreach ($sheets['lead'] ?? [] as $row) {
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
                'source' => $row['sumber'] ?? null,
                'platform' => $row['platform'] ?? null,
                'campaign_name' => $row['campaign'] ?? null,
                'notes' => $row['keterangan'] ?? null,
            ];
            if ($lead === null) {
                $lead = SalesLead::query()->create($attributes + [
                    'branch_id' => $branch->id,
                    'project_id' => $project->id,
                    'sales_user_id' => $sales->id,
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
            $leadRows[] = [$lead, $row];
        }

        $this->reconcileConsumers($branch, $sheets, $operationUuid, $summary);
        $this->reconcileSlik($branch, $sheets['bi_checking'] ?? [], $operationUuid, $actor, $summary);
        $this->reconcileAkad($branch, $sheets['akad'] ?? [], $operationUuid, $actor, $summary);
        $this->reconcileFreelance($branch, $sheets['data_sales'] ?? [], $operationUuid, $actor, $summary);
        $this->reconcileVisits($branch, $sheets['data_ceklok'] ?? [], $operationUuid, $summary);

        foreach ($leadRows as [$lead, $row]) {
            $this->reconcileLeadStatus($branch, $lead->fresh(), $row, $operationUuid, $actor, $summary);
        }

        return $summary;
    }

    private function reconcileConsumers(Branch $branch, array $sheets, string $operationUuid, array &$summary): void
    {
        foreach (['data_konsumen', 'data_konsumen_nup'] as $sheet) {
            foreach ($sheets[$sheet] ?? [] as $row) {
                $syncId = $row['oasis_sync_id'] ?? '';
                $link = $syncId !== '' ? SalesLeadConsumerLink::query()->where('branch_id', $branch->id)->where('oasis_sync_id', $syncId)->first() : null;
                if (! $link && $syncId !== '') {
                    $lead = SalesLead::query()->where('branch_id', $branch->id)->where('consumer_external_id', $syncId)->first();
                    $link = $lead?->consumerLinks()->where('status', 'completed')->first();
                }
                if (! $link && $sheet === 'data_konsumen' && filled($row['id_kavling'] ?? null)) {
                    $matches = SalesLeadConsumerLink::query()->where('branch_id', $branch->id)->where('id_kavling', $row['id_kavling'])->where('status', 'completed')->get();
                    $link = $matches->count() === 1 ? $matches->first() : null;
                }
                if (! $link) {
                    $this->issue($branch, null, 'consumer', $this->rowIdentity($sheet, $row), 'consumer_link_unconfirmed', ['sheet_name' => $sheet, 'id_kavling' => $row['id_kavling'] ?? null], $operationUuid, $summary);

                    continue;
                }
                $link->update([
                    'oasis_sync_id' => $syncId !== '' ? $syncId : $link->oasis_sync_id,
                    'sheet_name' => $sheet,
                    'remote_row_number' => $row['_row_number'],
                    'sheet_type' => $sheet,
                    'nik' => $row['no_ktp'] ?? $link->nik,
                    'id_kavling' => $row['id_kavling'] ?? $link->id_kavling,
                    'payload' => collect($row)->except('_row_number')->all(),
                    'status' => 'completed',
                    'converted_at' => $link->converted_at ?? now(),
                ]);
                if ($sheet === 'data_konsumen') {
                    $lead = SalesLead::query()->find($link->sales_lead_id);
                    $lead?->update([
                        'consumer_external_id' => $syncId !== '' ? $syncId : $lead->consumer_external_id,
                        'consumer_converted_at' => $lead->consumer_converted_at ?? now(),
                    ]);
                }
                $summary['linked']++;
            }
        }
    }

    private function reconcileSlik(Branch $branch, array $rows, string $operationUuid, ?User $actor, array &$summary): void
    {
        foreach ($rows as $row) {
            $consumers = SalesLeadConsumerLink::query()->where('branch_id', $branch->id)->where('id_kavling', $row['id_kavling'] ?? '')->where('status', 'completed')->get();
            if ($consumers->count() !== 1) {
                $this->issue($branch, null, 'slik', $this->rowIdentity('bi_checking', $row), 'consumer_kavling_ambiguous', ['id_kavling' => $row['id_kavling'] ?? null], $operationUuid, $summary);

                continue;
            }
            $consumer = $consumers->first();
            $syncId = $row['oasis_sync_id'] ?? '';
            $attempt = $syncId !== '' ? SalesLeadSlikAttempt::query()->where('branch_id', $branch->id)->where('oasis_sync_id', $syncId)->first() : null;
            $attempt ??= SalesLeadSlikAttempt::query()->where('branch_id', $branch->id)->where('consumer_link_id', $consumer->id)->where('id_kavling', $consumer->id_kavling)->first();
            $lead = SalesLead::query()->findOrFail($consumer->sales_lead_id);
            $result = $row['hasil_slik'] ?? '';
            $attributes = [
                'sales_lead_id' => $lead->id,
                'branch_id' => $branch->id,
                'actor_id' => $actor?->id,
                'consumer_link_id' => $consumer->id,
                'oasis_sync_id' => $syncId !== '' ? $syncId : $attempt?->oasis_sync_id,
                'sheet_name' => 'bi_checking',
                'remote_row_number' => $row['_row_number'],
                'status' => $result === '' ? 'submitted' : 'completed',
                'nik' => $row['no_ktp'] ?? $consumer->nik,
                'id_kavling' => $consumer->id_kavling,
                'slik_date' => $this->date($row['tanggal_slik'] ?? ''),
                'result' => $result ?: null,
                'slik_result' => $result ?: null,
                'checked_at' => now(),
                'attempt_number' => $attempt?->attempt_number ?? ($lead->slikAttempts()->count() + 1),
            ];
            $attempt ? $attempt->update($attributes) : SalesLeadSlikAttempt::query()->create($attributes);
            $summary['linked']++;
        }
    }

    private function reconcileAkad(Branch $branch, array $rows, string $operationUuid, ?User $actor, array &$summary): void
    {
        foreach ($rows as $row) {
            $identity = $this->rowIdentity('akad', $row);
            $akadAt = $this->date($row['tanggal_akad'] ?? '');
            if (! $akadAt) {
                $this->issue($branch, null, 'akad', $identity, 'akad_date_invalid', ['id_kavling' => $row['id_kavling'] ?? null], $operationUuid, $summary);

                continue;
            }
            $consumers = SalesLeadConsumerLink::query()->where('branch_id', $branch->id)->where('id_kavling', $row['id_kavling'] ?? '')->where('status', 'completed')->get();
            if ($consumers->count() !== 1) {
                $this->issue($branch, null, 'akad', $identity, 'consumer_kavling_ambiguous', ['id_kavling' => $row['id_kavling'] ?? null], $operationUuid, $summary);

                continue;
            }
            $consumer = $consumers->first();
            $syncId = $row['oasis_sync_id'] ?? '';
            $link = $syncId !== '' ? SalesLeadAkadLink::query()->where('branch_id', $branch->id)->where('oasis_sync_id', $syncId)->first() : null;
            $link ??= SalesLeadAkadLink::query()->where('branch_id', $branch->id)->where('consumer_link_id', $consumer->id)->where('id_kavling', $consumer->id_kavling)->first();
            $attributes = [
                'sales_lead_id' => $consumer->sales_lead_id,
                'branch_id' => $branch->id,
                'actor_id' => $actor?->id,
                'consumer_link_id' => $consumer->id,
                'oasis_sync_id' => $syncId !== '' ? $syncId : $link?->oasis_sync_id,
                'sheet_name' => 'akad',
                'remote_row_number' => $row['_row_number'],
                'status' => 'completed',
                'akad_id' => $row['no_ppjb_akad'] ?? null,
                'akad_reference' => $syncId !== '' ? $syncId : ($row['no_ppjb_akad'] ?? $consumer->id_kavling),
                'id_kavling' => $consumer->id_kavling,
                'akad_at' => $akadAt,
                'metadata' => collect($row)->except('_row_number')->all(),
            ];
            $link ? $link->update($attributes) : SalesLeadAkadLink::query()->create($attributes);
            $lead = SalesLead::query()->findOrFail($consumer->sales_lead_id);
            $lead->update(['akad_external_id' => $syncId !== '' ? $syncId : $lead->akad_external_id, 'akad_at' => $lead->akad_at ?? $akadAt]);
            $this->applyStatus($lead->fresh(), SalesLeadStatus::Akad, 'akad_sync', $this->stableSourceId('akad', $row), $akadAt, $actor);
            $summary['linked']++;
        }
    }

    private function reconcileFreelance(Branch $branch, array $rows, string $operationUuid, ?User $actor, array &$summary): void
    {
        foreach ($rows as $row) {
            $syncId = $row['oasis_sync_id'] ?? '';
            $link = $syncId !== '' ? SalesLeadFreelanceLink::query()->where('branch_id', $branch->id)->where('oasis_sync_id', $syncId)->first() : null;
            if (! $link) {
                $this->issue($branch, null, 'freelance', $this->rowIdentity('data_sales', $row), 'freelance_link_unconfirmed', [], $operationUuid, $summary);

                continue;
            }
            $link->update(['remote_row_number' => $row['_row_number'], 'status' => 'completed', 'nik_sales' => $row['nik_sales'] ?? null, 'sales_name' => $row['nama_sales'] ?? null]);
            $lead = SalesLead::query()->find($link->sales_lead_id);
            if ($lead) {
                $lead->update(['freelance_converted_at' => $lead->freelance_converted_at ?? now(), 'freelance_external_id' => $syncId]);
                $this->recordHistory($lead, SalesLeadStatus::Freelance, 'freelance_sync', $this->stableSourceId('data_sales', $row), now(), $actor);
            }
            $summary['linked']++;
        }
    }

    private function reconcileVisits(Branch $branch, array $rows, string $operationUuid, array &$summary): void
    {
        foreach ($rows as $row) {
            $syncId = $row['oasis_sync_id'] ?? '';
            $visit = $syncId !== '' ? SalesLeadSiteVisit::query()->where('branch_id', $branch->id)->where('oasis_sync_id', $syncId)->first() : null;
            if (! $visit) {
                $this->issue($branch, null, 'site_visit', $this->rowIdentity('data_ceklok', $row), 'site_visit_link_unconfirmed', [], $operationUuid, $summary);

                continue;
            }
            $visit->update(['remote_row_number' => $row['_row_number'], 'visit_date' => $this->date($row['tanggal_ceklok'] ?? ''), 'visit_status' => $row['status_ceklok'] ?? null]);
            $summary['linked']++;
        }
    }

    private function reconcileLeadStatus(Branch $branch, SalesLead $lead, array $row, string $operationUuid, ?User $actor, array &$summary): void
    {
        $raw = trim((string) ($row['status_lead'] ?? ''));
        $target = match (mb_strtolower($raw)) {
            '', 'no respon', 'no response' => SalesLeadStatus::NoResponse,
            'diskusi' => SalesLeadStatus::Discussion,
            'cek lokasi' => SalesLeadStatus::SiteVisit,
            'utj' => SalesLeadStatus::Utj,
            'cek silk', 'cek slik' => SalesLeadStatus::SlikCheck,
            'tidak lolos bi checking' => SalesLeadStatus::SlikRejected,
            'akad' => SalesLeadStatus::Akad,
            'jadi freelance' => SalesLeadStatus::Freelance,
            default => null,
        };
        if (! $target) {
            $this->issue($branch, $lead, 'lead_status', $this->rowIdentity('lead', $row), 'status_unknown', ['remote_status' => $raw], $operationUuid, $summary);

            return;
        }
        $allowed = match ($target) {
            SalesLeadStatus::Utj => $lead->consumerLinks()->where('sheet_type', 'data_konsumen')->where('status', 'completed')->exists(),
            SalesLeadStatus::SlikCheck, SalesLeadStatus::SlikRejected => $lead->slikAttempts()->exists(),
            SalesLeadStatus::Akad => $lead->akadLinks()->whereNotNull('akad_at')->exists(),
            SalesLeadStatus::Freelance => $lead->freelanceLinks()->where('status', 'completed')->exists(),
            default => true,
        };
        if (! $allowed) {
            $this->issue($branch, $lead, 'lead_status', $this->rowIdentity('lead', $row), 'status_link_unconfirmed', ['remote_status' => $raw], $operationUuid, $summary);

            return;
        }
        if ($target === SalesLeadStatus::Freelance) {
            $this->recordHistory($lead, $target, 'lead_sheet_sync', $this->stableSourceId('lead', $row), now(), $actor);

            return;
        }
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
        $projects = LeadMaster::query()->where('branch_id', $branch->id)->where('is_active', true)->where('project_name', $name)->get();

        return $projects->count() === 1 ? [$projects->first(), null] : [null, $projects->isEmpty() ? 'project_not_found' : 'project_ambiguous'];
    }

    private function uniqueAssignedSales(LeadMaster $project, string $name): array
    {
        $today = today()->toDateString();
        $users = User::query()
            ->join('project_user', 'project_user.user_id', '=', 'users.id')
            ->select('users.*')
            ->where('name', $name)
            ->where('users.is_active', true)
            ->where('project_user.project_id', $project->id)
            ->where('project_user.is_active', true)
            ->where(fn ($window) => $window->whereNull('project_user.assignment_start_date')->orWhereDate('project_user.assignment_start_date', '<=', $today))
            ->where(fn ($window) => $window->whereNull('project_user.assignment_end_date')->orWhereDate('project_user.assignment_end_date', '>=', $today))
            ->get();

        return $users->count() === 1 ? [$users->first(), null] : [null, $users->isEmpty() ? 'sales_not_found' : 'sales_ambiguous'];
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

        $businessId = match ($sheet) {
            'lead' => $row['id_lead'] ?? null,
            'data_konsumen_nup' => $row['nup'] ?? null,
            'bi_checking' => $row['id_kons'] ?? null,
            'akad' => $row['no_ppjb_akad'] ?? null,
            default => null,
        };
        if (filled($businessId)) {
            return $sheet.':'.$businessId;
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
