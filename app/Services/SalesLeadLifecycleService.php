<?php

namespace App\Services;

use App\Enums\SalesLeadStatus;
use App\Models\SalesLead;
use App\Models\SalesLeadConsumerLink;
use App\Models\SalesLeadFreelanceLink;
use App\Models\SalesLeadSiteVisit;
use App\Models\SalesLeadSlikAttempt;
use App\Models\SalesLeadStatusHistory;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesLeadLifecycleService
{
    public function __construct(
        private readonly WorkspaceAccessService $workspaceAccess,
    ) {}

    /** @return list<string> */
    public function allowedManualStatuses(): array
    {
        return array_map(fn (SalesLeadStatus $status) => $status->value, SalesLeadStatus::MANUAL);
    }

    /** @param iterable<string|SalesLeadStatus> $statuses */
    public function resolvePrimaryStatus(iterable $statuses): SalesLeadStatus
    {
        $resolved = SalesLeadStatus::NoResponse;

        foreach ($statuses as $status) {
            $candidate = SalesLeadStatus::fromInput($status);
            if ($candidate === SalesLeadStatus::Freelance) {
                continue;
            }

            if (($candidate->precedence() ?? -1) > ($resolved->precedence() ?? -1)) {
                $resolved = $candidate;
            }
        }

        return $resolved;
    }

    public function assertTransitionAllowed(
        SalesLead $lead,
        string|SalesLeadStatus $status,
        string $source = 'manual',
    ): void {
        $target = SalesLeadStatus::fromInput($status);

        if ($source === 'manual' && ! $target->isManual()) {
            throw new \DomainException('Status tersebut tidak dapat diubah secara manual.');
        }

        $current = $lead->current_status instanceof SalesLeadStatus
            ? $lead->current_status
            : SalesLeadStatus::fromInput($lead->current_status ?? SalesLeadStatus::NoResponse->value);

        if ($source === 'manual' && ! $current->isManual()) {
            throw new \DomainException('Status sistem tidak dapat diubah secara manual.');
        }
    }

    public function recordStatusHistory(
        SalesLead $lead,
        string|SalesLeadStatus $status,
        string $source,
        ?string $sourceId = null,
        ?User $actor = null,
        ?CarbonInterface $changedAt = null,
        array $metadata = [],
        ?string $operationUuid = null,
    ): SalesLeadStatusHistory {
        $status = SalesLeadStatus::fromInput($status);
        $safeMetadata = array_intersect_key($metadata, array_flip([
            'reason', 'previous_status', 'remote_status', 'sheet_name', 'remote_row_number',
            'reconciliation_item_id', 'legacy_field',
        ]));

        $identity = $operationUuid !== null
            ? ['branch_id' => $lead->branch_id, 'operation_uuid' => $operationUuid]
            : [
                'sales_lead_id' => $lead->id,
                'source' => $source,
                'source_id' => $sourceId,
                'status' => $status->value,
            ];

        $existing = SalesLeadStatusHistory::query()->where($identity)->first();
        if ($existing !== null) {
            if ($existing->sales_lead_id !== $lead->id) {
                throw new \DomainException('Identitas operasi sudah digunakan oleh lead lain.');
            }

            return $existing;
        }

        return SalesLeadStatusHistory::query()->create($identity + [
            'sales_lead_id' => $lead->id,
            'branch_id' => $lead->branch_id,
            'actor_id' => $actor?->id,
            'status' => $status->value,
            'source' => $source,
            'source_id' => $sourceId,
            'operation_uuid' => $operationUuid,
            'changed_at' => $changedAt ?? now(),
            'metadata' => $safeMetadata ?: null,
        ]);
    }

    public function setManualStatus(
        SalesLead $lead,
        string|SalesLeadStatus $status,
        User $actor,
        ?CarbonInterface $changedAt = null,
        ?string $operationUuid = null,
    ): SalesLead {
        return DB::transaction(function () use ($lead, $status, $actor, $changedAt, $operationUuid): SalesLead {
            $locked = SalesLead::query()->lockForUpdate()->findOrFail($lead->id);
            $target = SalesLeadStatus::fromInput($status);
            $this->assertTransitionAllowed($locked, $target);
            $changedAt ??= now();

            if ($locked->current_status !== $target) {
                $previousStatus = $locked->current_status->value;
                $this->updateLeadSheetStatus($locked, $target);
                $locked->update([
                    'current_status' => $target,
                    'current_status_changed_at' => $changedAt,
                    'current_status_source' => 'manual',
                    'current_status_source_id' => (string) $actor->id,
                    'updated_by' => $actor->id,
                ]);

                $this->recordStatusHistory(
                    $locked,
                    $target,
                    'manual',
                    (string) $actor->id,
                    $actor,
                    $changedAt,
                    ['previous_status' => $previousStatus],
                    $operationUuid,
                );
            }

            return $locked->fresh();
        });
    }

    public function transitionSystemStatus(
        SalesLead $lead,
        SalesLeadStatus $status,
        string $source,
        string $sourceId,
        User $actor,
        string $operationUuid,
        ?CarbonInterface $changedAt = null,
        array $metadata = [],
    ): SalesLead {
        if (! Str::isUuid($operationUuid)) {
            throw new \DomainException('Identitas operasi tidak valid.');
        }

        $changedAt ??= now();
        $current = $lead->current_status instanceof SalesLeadStatus
            ? $lead->current_status
            : SalesLeadStatus::fromInput($lead->current_status ?? SalesLeadStatus::NoResponse->value);
        $target = $status === SalesLeadStatus::Freelance
            ? $current
            : $this->resolvePrimaryStatus([$current, $status]);

        $this->updateLeadSheetStatus($lead, $status === SalesLeadStatus::Freelance ? SalesLeadStatus::Freelance : $target);

        if ($target !== $current) {
            $lead->update([
                'current_status' => $target,
                'current_status_changed_at' => $changedAt,
                'current_status_source' => $source,
                'current_status_source_id' => $sourceId,
                'updated_by' => $actor->id,
            ]);
        }

        $this->recordStatusHistory(
            $lead,
            $status,
            $source,
            $sourceId,
            $actor,
            $changedAt,
            ['previous_status' => $current->value] + $metadata,
            $operationUuid,
        );

        return $lead->fresh();
    }

    public function recordSiteVisit(SalesLead $lead, array $data, User $actor): SalesLeadSiteVisit
    {
        $operationUuid = $this->operationUuid($data);

        return DB::transaction(function () use ($lead, $data, $actor, $operationUuid): SalesLeadSiteVisit {
            $locked = $this->lockLead($lead);
            if ($existing = $this->existingOperation(SalesLeadSiteVisit::class, $locked, $operationUuid)) {
                return $existing;
            }

            $completed = ($data['completion'] ?? null) === 'complete';
            $result = $completed && $this->syncEnabled() ? $this->writer()->append($locked, 'data_ceklok', [
                'nama_konsumen' => $locked->customer_name,
                'tanggal_ceklok' => $data['tanggal'],
                'waktu_ceklok' => $data['waktu'],
                'status_ceklok' => $data['status'],
                'keterangan' => $data['keterangan'] ?? null,
            ], $operationUuid) : null;

            $visit = SalesLeadSiteVisit::query()->create([
                'sales_lead_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'actor_id' => $actor->id,
                'operation_uuid' => $operationUuid,
                'oasis_sync_id' => $result?->syncId,
                'sheet_name' => $result?->sheetName,
                'remote_row_number' => $result?->rowNumber,
                'status' => $completed ? 'completed' : 'incomplete',
                'visited_at' => $completed ? $data['tanggal'].' '.($this->visitClock($data['waktu'])) : null,
                'visit_date' => $completed ? $data['tanggal'] : null,
                'visit_time' => $completed ? $data['waktu'] : null,
                'visit_status' => $completed ? $data['status'] : null,
                'notes' => $data['keterangan'] ?? null,
                'is_completed' => $completed,
            ]);

            $this->transitionSystemStatus($locked, SalesLeadStatus::SiteVisit, 'site_visit', (string) $visit->id, $actor, $operationUuid, metadata: [
                'sheet_name' => $result?->sheetName,
                'remote_row_number' => $result?->rowNumber,
                'reason' => $completed ? $data['status'] : 'isi_nanti',
            ]);

            return $visit;
        });
    }

    public function convertToConsumer(SalesLead $lead, array $data, User $actor): SalesLeadConsumerLink
    {
        $operationUuid = $this->operationUuid($data);

        return DB::transaction(function () use ($lead, $data, $actor, $operationUuid): SalesLeadConsumerLink {
            $locked = $this->lockLead($lead);
            if ($existing = $this->existingOperation(SalesLeadConsumerLink::class, $locked, $operationUuid)) {
                return $existing;
            }
            $project = $locked->project()->first();
            if ($project === null || (int) $project->branch_id !== (int) $locked->branch_id) {
                throw ValidationException::withMessages(['project_id' => 'Proyek lead tidak valid untuk cabang ini.']);
            }
            $sheetName = $project->is_nup_eligible ? 'data_konsumen_nup' : 'data_konsumen';
            if ($locked->consumerLinks()->where('sheet_type', $sheetName)->where('status', 'completed')->exists()) {
                throw ValidationException::withMessages([
                    'lead' => $sheetName === 'data_konsumen'
                        ? 'Lead ini sudah dikonversi menjadi konsumen.'
                        : 'Lead ini sudah memiliki data konsumen NUP.',
                ]);
            }
            if (SalesLeadConsumerLink::query()
                ->where('branch_id', $locked->branch_id)
                ->where('sales_lead_id', '!=', $locked->id)
                ->where('nik', $data['nik'])
                ->where('status', 'completed')
                ->exists()) {
                throw ValidationException::withMessages(['nik' => 'NIK sudah terdaftar pada proses konsumen di cabang ini.']);
            }
            if ($sheetName === 'data_konsumen' && blank($data['id_kavling'] ?? null)) {
                throw ValidationException::withMessages(['id_kavling' => 'ID kavling wajib diisi untuk konsumen normal.']);
            }

            $supportedFields = $sheetName === 'data_konsumen_nup'
                ? ['nup', 'tanggal_lahir', 'pekerjaan', 'alamat', 'kelurahan', 'kecamatan', 'kabupaten/kota', 'nama_kondar', 'no_hp_kondar', 'keterangan']
                : ['id_kavling', 'tanggal_lahir', 'pekerjaan', 'detail_pekerjaan', 'alamat', 'kelurahan', 'kecamatan', 'kabupaten/kota', 'nama_kondar', 'no_hp_kondar', 'status_cash', 'keterangan'];
            $fields = array_intersect_key($data, array_flip($supportedFields));
            $fields += [
                'no_ktp' => $data['nik'],
                'nama_konsumen' => $locked->customer_name,
                'no_hp' => $locked->phone,
            ];
            $result = $this->syncEnabled() ? $this->writer()->append($locked, $sheetName, $fields, $operationUuid) : null;

            $link = SalesLeadConsumerLink::query()->create([
                'sales_lead_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'actor_id' => $actor->id,
                'operation_uuid' => $operationUuid,
                'oasis_sync_id' => $result?->syncId,
                'sheet_name' => $result?->sheetName,
                'remote_row_number' => $result?->rowNumber,
                'status' => 'completed',
                'consumer_reference' => $result?->syncId ?? $operationUuid,
                'sheet_type' => $sheetName,
                'nik' => $data['nik'],
                'id_kavling' => $data['id_kavling'] ?? null,
                'payload' => $fields,
                'converted_at' => now(),
            ]);
            if ($sheetName === 'data_konsumen') {
                $locked->update([
                    'consumer_converted_at' => now(),
                    'consumer_external_id' => $result?->syncId,
                    'linked_consumer_reference' => $result?->syncId ?? $operationUuid,
                ]);
                $this->transitionSystemStatus($locked->fresh(), SalesLeadStatus::Utj, 'consumer', (string) $link->id, $actor, $operationUuid, metadata: [
                    'sheet_name' => $result?->sheetName,
                    'remote_row_number' => $result?->rowNumber,
                ]);
            }

            return $link;
        });
    }

    public function submitToSlik(SalesLead $lead, array $data, User $actor): SalesLeadSlikAttempt
    {
        $operationUuid = $this->operationUuid($data);

        return DB::transaction(function () use ($lead, $data, $actor, $operationUuid): SalesLeadSlikAttempt {
            $locked = $this->lockLead($lead);
            if ($existing = $this->existingOperation(SalesLeadSlikAttempt::class, $locked, $operationUuid)) {
                return $existing;
            }
            $consumer = $locked->consumerLinks()->where('sheet_type', 'data_konsumen')->where('status', 'completed')->latest('id')->first();
            if ($consumer === null || blank($consumer->nik) || blank($consumer->id_kavling)) {
                throw ValidationException::withMessages(['lead' => 'SLIK memerlukan konsumen normal dengan NIK dan ID kavling.']);
            }
            if ($locked->slikAttempts()->where('status', 'submitted')->exists()) {
                throw ValidationException::withMessages(['lead' => 'Lead ini masih memiliki pengajuan SLIK aktif.']);
            }

            $result = $this->syncEnabled() ? $this->writer()->append($locked, 'bi_checking', [
                'id_kavling' => $consumer->id_kavling,
                'no_ktp' => $consumer->nik,
                'tanggal_slik' => $data['tanggal_slik'],
                'keterangan' => $data['keterangan'] ?? null,
            ], $operationUuid) : null;
            $attempt = SalesLeadSlikAttempt::query()->create([
                'sales_lead_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'actor_id' => $actor->id,
                'consumer_link_id' => $consumer->id,
                'operation_uuid' => $operationUuid,
                'oasis_sync_id' => $result?->syncId,
                'sheet_name' => $result?->sheetName,
                'remote_row_number' => $result?->rowNumber,
                'status' => 'submitted',
                'nik' => $consumer->nik,
                'id_kavling' => $consumer->id_kavling,
                'slik_date' => $data['tanggal_slik'],
                'checked_at' => now(),
                'notes' => $data['keterangan'] ?? null,
                'attempt_number' => $locked->slikAttempts()->count() + 1,
            ]);
            $locked->update(['slik_external_id' => $result?->syncId]);
            $this->transitionSystemStatus($locked->fresh(), SalesLeadStatus::SlikCheck, 'slik', (string) $attempt->id, $actor, $operationUuid, metadata: [
                'sheet_name' => $result?->sheetName,
                'remote_row_number' => $result?->rowNumber,
            ]);

            return $attempt;
        });
    }

    public function markSlikRejected(SalesLead $lead, SalesLeadSlikAttempt $attempt, array $data, User $actor): SalesLeadSlikAttempt
    {
        $operationUuid = $this->operationUuid($data);

        return DB::transaction(function () use ($lead, $attempt, $data, $actor, $operationUuid): SalesLeadSlikAttempt {
            $locked = $this->lockLead($lead);
            $lockedAttempt = SalesLeadSlikAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ((int) $lockedAttempt->sales_lead_id !== (int) $locked->id || (int) $lockedAttempt->branch_id !== (int) $locked->branch_id) {
                throw ValidationException::withMessages(['slik_attempt_id' => 'Pengajuan SLIK tidak sesuai dengan lead.']);
            }
            if ($lockedAttempt->status === 'rejected') {
                return $lockedAttempt;
            }
            if ($lockedAttempt->status !== 'submitted' || ($this->syncEnabled() && blank($lockedAttempt->oasis_sync_id))) {
                throw ValidationException::withMessages(['slik_attempt_id' => 'Pengajuan SLIK aktif tidak ditemukan.']);
            }

            $result = $this->syncEnabled() ? $this->writer()->updateBySyncId($locked, 'bi_checking', $lockedAttempt->oasis_sync_id, [
                'hasil_slik' => $data['hasil_slik'],
                'keterangan' => $data['keterangan'],
            ]) : null;
            $lockedAttempt->update([
                'status' => 'rejected',
                'result' => $data['hasil_slik'],
                'slik_result' => $data['hasil_slik'],
                'notes' => $data['keterangan'],
                'rejected_at' => now(),
                'remote_row_number' => $result?->rowNumber,
            ]);
            $this->transitionSystemStatus($locked, SalesLeadStatus::SlikRejected, 'slik_rejection', (string) $lockedAttempt->id, $actor, $operationUuid, metadata: [
                'sheet_name' => $result?->sheetName,
                'remote_row_number' => $result?->rowNumber,
                'reason' => $data['hasil_slik'],
            ]);

            return $lockedAttempt->fresh();
        });
    }

    public function convertToFreelance(SalesLead $lead, array $data, User $actor): SalesLeadFreelanceLink
    {
        $operationUuid = $this->operationUuid($data);

        return DB::transaction(function () use ($lead, $data, $actor, $operationUuid): SalesLeadFreelanceLink {
            $locked = $this->lockLead($lead);
            if ($existing = $this->existingOperation(SalesLeadFreelanceLink::class, $locked, $operationUuid)) {
                return $existing;
            }
            if ($locked->freelanceLinks()->where('status', 'completed')->exists()) {
                throw ValidationException::withMessages(['lead' => 'Lead ini sudah dikonversi menjadi freelance.']);
            }

            $sales = $locked->sales()->first();
            $supervisor = $sales?->supervisor()->first();
            $coordinator = $this->isAuthorizedCoordinator($supervisor, $locked)
                ? $supervisor
                : User::query()->find($data['coordinator_user_id'] ?? null);
            if (! $this->isAuthorizedCoordinator($coordinator, $locked)) {
                throw ValidationException::withMessages(['coordinator_user_id' => 'Koordinator harus aktif dan berada dalam cabang serta proyek lead.']);
            }
            if ($supervisor !== null && $this->isAuthorizedCoordinator($supervisor, $locked)
                && isset($data['coordinator_user_id']) && (int) $data['coordinator_user_id'] !== (int) $supervisor->id) {
                throw ValidationException::withMessages(['coordinator_user_id' => 'Koordinator harus menggunakan atasan aktif Sales lead.']);
            }

            $result = $this->syncEnabled() ? $this->writer()->append($locked, 'data_sales', [
                'nik_sales' => 'OJT',
                'nama_sales' => $locked->customer_name,
                'nik_koordinator' => $data['nik_koordinator'],
                'nama_koordinator' => $coordinator->name,
            ], $operationUuid) : null;
            $link = SalesLeadFreelanceLink::query()->create([
                'sales_lead_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'actor_id' => $actor->id,
                'coordinator_user_id' => $coordinator->id,
                'operation_uuid' => $operationUuid,
                'oasis_sync_id' => $result?->syncId,
                'sheet_name' => $result?->sheetName,
                'remote_row_number' => $result?->rowNumber,
                'status' => 'completed',
                'freelance_reference' => $result?->syncId ?? $operationUuid,
                'nik_sales' => 'OJT',
                'sales_name' => $locked->customer_name,
                'coordinator_nik' => $data['nik_koordinator'],
                'coordinator_name' => $coordinator->name,
                'converted_at' => now(),
            ]);
            $locked->update([
                'freelance_converted_at' => now(),
                'freelance_external_id' => $result?->syncId,
            ]);
            $this->transitionSystemStatus($locked->fresh(), SalesLeadStatus::Freelance, 'freelance', (string) $link->id, $actor, $operationUuid, metadata: [
                'sheet_name' => $result?->sheetName,
                'remote_row_number' => $result?->rowNumber,
            ]);

            return $link;
        });
    }

    private function operationUuid(array $data): string
    {
        $uuid = $data['operation_uuid'] ?? (string) Str::uuid();
        if (! Str::isUuid($uuid)) {
            throw ValidationException::withMessages(['operation_uuid' => 'Identitas operasi tidak valid.']);
        }

        return $uuid;
    }

    private function syncEnabled(): bool
    {
        return (bool) config('services.google_sheets.sales_lead_sync_enabled');
    }

    private function writer(): SalesLeadSpreadsheetWriter
    {
        return app(SalesLeadSpreadsheetWriter::class);
    }

    private function updateLeadSheetStatus(SalesLead $lead, SalesLeadStatus $status): void
    {
        if (! $this->syncEnabled() || ! $lead->external_sync_id) {
            return;
        }

        $this->writer()->updateBySyncId($lead, 'lead', $lead->external_sync_id, [
            'status_lead' => $status->spreadsheetValue(),
        ]);
    }

    private function lockLead(SalesLead $lead): SalesLead
    {
        $locked = SalesLead::query()->with(['project', 'sales.supervisor'])->lockForUpdate()->findOrFail($lead->id);
        if ((int) $locked->branch_id !== (int) $lead->branch_id || (int) $locked->project_id !== (int) $lead->project_id) {
            throw ValidationException::withMessages(['lead' => 'Data organisasi lead telah berubah. Muat ulang halaman.']);
        }

        return $locked;
    }

    private function existingOperation(string $model, SalesLead $lead, string $operationUuid): ?object
    {
        $existing = $model::query()->where('branch_id', $lead->branch_id)->where('operation_uuid', $operationUuid)->first();
        if ($existing !== null && (int) $existing->sales_lead_id !== (int) $lead->id) {
            throw ValidationException::withMessages(['operation_uuid' => 'Identitas operasi sudah digunakan oleh lead lain.']);
        }

        return $existing;
    }

    private function isAuthorizedCoordinator(?User $user, SalesLead $lead): bool
    {
        return $user !== null
            && $user->isAccountActive()
            && $this->workspaceAccess->canViewBranch($user, $lead->branch_id)
            && $this->workspaceAccess->canAccessProject($user, $lead->project_id);
    }

    private function visitClock(string $period): string
    {
        return match ($period) {
            'pagi' => '08:00:00',
            'siang' => '12:00:00',
            'sore' => '16:00:00',
            'malam' => '19:00:00',
        };
    }
}
