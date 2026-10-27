<?php

namespace App\Services;

use App\Enums\SalesLeadStatus;
use App\Models\SalesLead;
use App\Models\User;
use App\Policies\SalesLeadPolicy;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SalesLeadService
{
    private const CREATE_IDENTITY_INDEX = 'sales_leads_branch_sync_unique';

    private const CREATE_RETRY_CONFLICT_MESSAGE = 'Permintaan ini sudah pernah diproses dengan data berbeda. Muat ulang formulir lalu coba kembali.';

    private const CREATE_RETRY_DELETED_MESSAGE = 'Permintaan ini sudah pernah diproses, tetapi Lead tersebut sudah dihapus. Muat ulang formulir lalu coba kembali dengan identitas operasi baru.';

    /** @var array<string, string> */
    private const HISTORICAL_LIFECYCLE_FIELDS = [
        'contacted_at' => 'Lead historis tidak dapat mengubah tahapan melalui formulir edit.',
        'met_at' => 'Lead historis tidak dapat mengubah tahapan melalui formulir edit.',
        'surveyed_at' => 'Lead historis tidak dapat mengubah tahapan melalui formulir edit.',
        'utj_at' => 'Lead historis tidak dapat mengubah tahapan melalui formulir edit.',
        'documents_completed_at' => 'Lead historis tidak dapat mengubah tahapan melalui formulir edit.',
        'akad_at' => 'Lead historis tidak dapat mengubah tahapan melalui formulir edit.',
        'current_status' => 'Lead historis tidak dapat mengubah status melalui formulir edit.',
        'current_status_changed_at' => 'Lead historis tidak dapat mengubah metadata status melalui formulir edit.',
        'current_status_source' => 'Lead historis tidak dapat mengubah metadata status melalui formulir edit.',
        'current_status_source_id' => 'Lead historis tidak dapat mengubah metadata status melalui formulir edit.',
        'consumer_converted_at' => 'Lead historis tidak dapat mengubah konversi konsumen melalui formulir edit.',
        'consumer_external_id' => 'Lead historis tidak dapat mengubah konversi konsumen melalui formulir edit.',
        'linked_consumer_reference' => 'Lead historis tidak dapat mengubah konversi konsumen melalui formulir edit.',
        'freelance_converted_at' => 'Lead historis tidak dapat mengubah konversi freelance melalui formulir edit.',
        'freelance_external_id' => 'Lead historis tidak dapat mengubah konversi freelance melalui formulir edit.',
        'slik_external_id' => 'Lead historis tidak dapat mengubah proses SLIK melalui formulir edit.',
        'akad_external_id' => 'Lead historis tidak dapat mengubah proses Akad melalui formulir edit.',
    ];

    /** @var list<string> */
    private const HISTORICAL_LIFECYCLE_DATE_FIELDS = [
        'contacted_at',
        'met_at',
        'surveyed_at',
        'utj_at',
        'documents_completed_at',
        'akad_at',
        'current_status_changed_at',
        'consumer_converted_at',
        'freelance_converted_at',
    ];

    public function __construct(
        private readonly PhoneNormalizationService $phones,
        private readonly SalesLeadLifecycleService $lifecycle,
        private readonly ?SalesLeadBridgeModeService $bridgeModes = null,
        private readonly ?SalesLeadBridgeService $bridge = null,
        private readonly ?SalesLeadPolicy $salesLeadPolicy = null,
    ) {}

    public function create(array $data, User $actor): SalesLead
    {
        $this->assertManualStatus($data['current_status'] ?? null);
        $data = $this->prepareCreateData($data, $actor);
        $existing = $this->findCreateIdentity($data);
        if ($existing !== null) {
            return $this->resolveCreateRetry($existing, $data);
        }

        try {
            $lead = DB::transaction(function () use ($data, $actor): SalesLead {
                $lead = SalesLead::create($data);
                $this->lifecycle->recordStatusHistory(
                    $lead,
                    $lead->current_status,
                    'manual',
                    (string) $actor->id,
                    $actor,
                    $lead->current_status_changed_at,
                    operationUuid: $lead->external_sync_id,
                );
                $lead->logSalesActivity('created', $this->activityContext($lead));

                return $lead->fresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isCreateIdentityViolation($exception)) {
                throw $exception;
            }

            $existing = $this->findCreateIdentity($data);
            if ($existing === null) {
                throw $exception;
            }

            return $this->resolveCreateRetry($existing, $data);
        }

        $this->schedulePush($lead, $actor);

        return $lead;
    }

    /**
     * Normalize the request fields that define a create operation before the
     * idempotency lookup and before persistence.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareCreateData(array $data, User $actor): array
    {
        $data['id_promo'] = $data['promo_name'] ?? null;
        unset($data['promo_name']);
        $data['normalized_phone'] = $this->phones->normalize($data['phone'] ?? null);
        unset($data['lead_source_id']);
        $data['lead_source_id'] = null;
        $data['source_name_snapshot'] = $data['source'];
        $data['current_status'] ??= SalesLeadStatus::NoResponse->value;
        $data['current_status_changed_at'] = now();
        $data['current_status_source'] = 'manual';
        $data['current_status_source_id'] = (string) $actor->id;
        $data['external_sync_id'] = $data['operation_uuid'] ?? (string) Str::uuid();
        unset($data['operation_uuid']);
        $data['sync_status'] = 'pending_create';
        $data['last_synced_at'] = null;
        $data['last_sync_error'] = null;
        $data['created_by'] = $actor->id;
        $data['updated_by'] = $actor->id;

        return $data;
    }

    /**
     * Find a create operation in both active and soft-deleted records.
     *
     * @param  array<string, mixed>  $data
     */
    private function findCreateIdentity(array $data): ?SalesLead
    {
        return SalesLead::withTrashed()
            ->where('branch_id', (int) $data['branch_id'])
            ->where('external_sync_id', (string) $data['external_sync_id'])
            ->first();
    }

    /**
     * Resolve an existing create operation without repeating its side effects.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveCreateRetry(SalesLead $existing, array $data): SalesLead
    {
        if ($existing->trashed()) {
            throw ValidationException::withMessages([
                'operation_uuid' => self::CREATE_RETRY_DELETED_MESSAGE,
            ]);
        }

        if (! $this->createPayloadMatches($existing, $data)) {
            throw ValidationException::withMessages([
                'operation_uuid' => self::CREATE_RETRY_CONFLICT_MESSAGE,
            ]);
        }

        return $existing->fresh();
    }

    /**
     * Compare only canonical business values from the original create.
     * Retry metadata, timestamps, database IDs, and sync delivery state are
     * intentionally excluded.
     *
     * @param  array<string, mixed>  $data
     */
    private function createPayloadMatches(SalesLead $existing, array $data): bool
    {
        return $this->createPayload($existing) === $this->createPayload($data);
    }

    /**
     * @param  SalesLead|array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function createPayload(SalesLead|array $value): array
    {
        if ($value instanceof SalesLead) {
            return [
                'branch_id' => (int) $value->branch_id,
                'project_id' => (int) $value->project_id,
                'sales_user_id' => (int) $value->sales_user_id,
                'lead_date' => $value->lead_date?->toDateString(),
                'customer_name' => (string) $value->customer_name,
                'normalized_phone' => $value->normalized_phone,
                'source' => $value->source,
                'platform' => $value->platform,
                'campaign_id' => $value->campaign_id,
                'campaign_name' => $value->campaign_name,
                'id_promo' => $value->id_promo,
                'notes' => $value->notes,
                'linked_consumer_reference' => $value->linked_consumer_reference,
                'current_status' => $value->current_status?->value,
            ];
        }

        return [
            'branch_id' => (int) $value['branch_id'],
            'project_id' => (int) $value['project_id'],
            'sales_user_id' => (int) $value['sales_user_id'],
            'lead_date' => CarbonImmutable::parse((string) $value['lead_date'])->toDateString(),
            'customer_name' => (string) $value['customer_name'],
            'normalized_phone' => $value['normalized_phone'],
            'source' => $value['source'],
            'platform' => $value['platform'],
            'campaign_id' => $value['campaign_id'] ?? null,
            'campaign_name' => $value['campaign_name'],
            'id_promo' => $value['id_promo'] ?? null,
            'notes' => $value['notes'] ?? null,
            'linked_consumer_reference' => $value['linked_consumer_reference'] ?? null,
            'current_status' => $value['current_status'] instanceof SalesLeadStatus
                ? $value['current_status']->value
                : SalesLeadStatus::fromInput((string) $value['current_status'])->value,
        ];
    }

    private function isCreateIdentityViolation(UniqueConstraintViolationException $exception): bool
    {
        if ($exception->index === self::CREATE_IDENTITY_INDEX) {
            return true;
        }

        $columns = array_map(
            static fn (string $column): string => Str::afterLast($column, '.'),
            $exception->columns,
        );
        sort($columns);

        return $columns === ['branch_id', 'external_sync_id'];
    }

    public function update(SalesLead $lead, array $data, User $actor): SalesLead
    {
        $this->assertManualStatus($data['current_status'] ?? null);
        $updated = DB::transaction(function () use ($lead, $data, $actor): SalesLead {
            $locked = SalesLead::query()->lockForUpdate()->findOrFail($lead->id);
            $this->assertHistoricalImmutableFields($locked, $data, $actor);
            $data['id_promo'] = $data['promo_name'] ?? null;
            unset($data['promo_name']);
            if (blank($data['current_status'] ?? null)) {
                unset($data['current_status']);
            }
            if (($locked->remote_target_branch_id !== null || $locked->delivery_attempted_at !== null || $locked->last_synced_at !== null) && (int) $locked->branch_id !== (int) $data['branch_id']) {
                throw new \DomainException('Lead yang sudah memiliki tujuan remote tidak dapat dipindahkan ke cabang lain.');
            }

            $changedFields = array_keys(array_filter($data, fn ($value, $key) => $locked->{$key} != $value, ARRAY_FILTER_USE_BOTH));
            $data['normalized_phone'] = $this->phones->normalize($data['phone'] ?? null);
            unset($data['lead_source_id'], $data['source_name_snapshot']);
            $previousStatus = $locked->current_status;
            $statusChanged = isset($data['current_status']) && $previousStatus !== SalesLeadStatus::fromInput($data['current_status']);
            if ($statusChanged) {
                $data['current_status_changed_at'] = now();
                $data['current_status_source'] = 'manual';
                $data['current_status_source_id'] = (string) $actor->id;
            }
            $data['updated_by'] = $actor->id;

            $data['sync_status'] = $locked->last_synced_at ? 'pending_update' : 'pending_create';
            $data['last_sync_error'] = null;
            $locked->update($data);
            if ($statusChanged) {
                $this->lifecycle->recordStatusHistory(
                    $locked,
                    $locked->current_status,
                    'manual',
                    (string) $actor->id,
                    $actor,
                    $locked->current_status_changed_at,
                    ['previous_status' => $previousStatus->value],
                    (string) Str::uuid(),
                );
            }
            $locked->logSalesActivity('updated', $this->activityContext($locked) + ['changed_fields' => $changedFields]);

            return $locked->fresh();
        });
        $this->schedulePush($updated, $actor);

        return $updated;
    }

    private function assertHistoricalImmutableFields(SalesLead $lead, array $data, User $actor): void
    {
        $policy = $this->salesLeadPolicy ?? app(SalesLeadPolicy::class);
        if (! $policy->canEditHistoricalOwnedLead($actor, $lead)) {
            return;
        }

        $immutableFields = [
            'branch_id' => [(int) $lead->branch_id, 'Lead historis hanya dapat diedit pada cabang asal.'],
            'project_id' => [(int) $lead->project_id, 'Lead historis hanya dapat diedit pada proyek asal.'],
            'sales_user_id' => [(int) $lead->sales_user_id, 'Lead historis hanya dapat diedit tanpa mengganti Sales pemilik.'],
        ];

        foreach ($immutableFields as $field => [$originalValue, $message]) {
            if (array_key_exists($field, $data) && (int) $data[$field] !== $originalValue) {
                throw ValidationException::withMessages([$field => $message]);
            }
        }

        foreach (self::HISTORICAL_LIFECYCLE_FIELDS as $field => $message) {
            if (array_key_exists($field, $data) && $this->historicalFieldChanged($lead, $field, $data[$field])) {
                throw ValidationException::withMessages([$field => $message]);
            }
        }
    }

    private function historicalFieldChanged(SalesLead $lead, string $field, mixed $submittedValue): bool
    {
        if ($field === 'current_status') {
            return ! blank($submittedValue)
                && SalesLeadStatus::fromInput((string) $submittedValue) !== $lead->current_status;
        }

        $originalValue = $lead->getRawOriginal($field);
        if ($originalValue === null || blank($submittedValue)) {
            return $originalValue !== null || filled($submittedValue);
        }

        if (in_array($field, self::HISTORICAL_LIFECYCLE_DATE_FIELDS, true)) {
            return ! Carbon::parse($submittedValue)->equalTo($lead->{$field});
        }

        return (string) $originalValue !== (string) $submittedValue;
    }

    public function delete(SalesLead $lead, User $actor): void
    {
        [$candidate, $requiresTombstone, $expectedFingerprint] = DB::transaction(function () use ($lead): array {
            $locked = SalesLead::query()->lockForUpdate()->findOrFail($lead->id);
            if ($locked->consumerLinks()->exists() || $locked->consumer_converted_at !== null || filled($locked->linked_consumer_reference)) {
                throw new \DomainException('Lead ini sudah terhubung ke data konsumen dan tidak dapat dihapus.');
            }
            $requiresTombstone = $locked->remote_target_branch_id !== null
                || $locked->delivery_attempted_at !== null
                || $locked->last_synced_at !== null;
            if ($requiresTombstone) {
                $locked->update(['sync_status' => 'pending_delete', 'delete_pending_at' => now(), 'last_sync_error' => null]);
            }

            $candidate = $locked->fresh(['branch']);

            return [$candidate, (bool) $requiresTombstone, $this->deleteFingerprint($candidate)];
        });

        if ($requiresTombstone) {
            try {
                if ($this->bridge === null) {
                    throw new \DomainException('Bridge lead belum tersedia.');
                }
                $this->bridge->tombstone($candidate, $actor);
            } catch (Throwable $exception) {
                report($exception);
                SalesLead::query()->whereKey($candidate->id)->update(['sync_status' => 'pending_delete', 'delete_pending_at' => now(), 'last_sync_error' => 'Tombstone spreadsheet gagal.']);
                throw new \DomainException('Lead belum dapat dihapus karena tombstone spreadsheet gagal.');
            }
        }

        DB::transaction(function () use ($candidate, $expectedFingerprint): void {
            $current = SalesLead::query()->lockForUpdate()->findOrFail($candidate->id);
            if ($current->consumerLinks()->exists() || $current->consumer_converted_at !== null || filled($current->linked_consumer_reference)) {
                throw new \DomainException('Lead berubah setelah tombstone dan tidak dapat dihapus.');
            }
            if (! hash_equals($expectedFingerprint, $this->deleteFingerprint($current))) {
                $current->update(['sync_status' => 'pending_delete', 'delete_pending_at' => now(), 'last_sync_error' => 'Lead berubah setelah tombstone.']);
                throw new \DomainException('Lead berubah setelah tombstone dan tidak dapat dihapus.');
            }
            $current->logSalesActivity('deleted', ['branch_id' => $current->branch_id, 'project_id' => $current->project_id, 'sales_user_id' => $current->sales_user_id]);
            $current->delete();
        });
    }

    public function setStage(SalesLead $lead, string $stage, mixed $timestamp, User $actor): SalesLead
    {
        $lead->update([$stage => $timestamp, 'updated_by' => $actor->id]);
        $lead->logSalesActivity('stage_updated', $this->activityContext($lead) + ['stage' => $stage, 'stage_label' => SalesLead::STAGES[$stage]]);

        return $lead->fresh();
    }

    public function reverseStage(SalesLead $lead, string $stage, User $actor): SalesLead
    {
        $position = array_search($stage, SalesLead::STAGE_ORDER, true);
        $updates = ['updated_by' => $actor->id];
        foreach (array_slice(SalesLead::STAGE_ORDER, $position) as $stageToClear) {
            $updates[$stageToClear] = null;
        }
        $lead->update($updates);
        $lead->logSalesActivity('stage_reversed', $this->activityContext($lead) + ['stage' => $stage, 'stage_label' => SalesLead::STAGES[$stage]]);

        return $lead->fresh();
    }

    private function deleteFingerprint(SalesLead $lead): string
    {
        return hash('sha256', json_encode($lead->only([
            'branch_id', 'project_id', 'sales_user_id', 'lead_date', 'customer_name', 'phone', 'source', 'platform',
            'campaign_name', 'notes', 'current_status', 'external_sync_id', 'remote_target_branch_id', 'last_synced_at',
            'consumer_converted_at', 'linked_consumer_reference', 'updated_by',
        ]), JSON_THROW_ON_ERROR));
    }

    private function schedulePush(SalesLead $lead, User $actor): void
    {
        $lead->loadMissing('branch');
        if ($this->bridgeModes === null || $this->bridge === null || ! $this->bridgeModes->isPushEnabled($lead->branch)) {
            return;
        }
        DB::afterCommit(function () use ($lead, $actor): void {
            try {
                $this->bridge->push($lead->fresh(), $actor);
            } catch (Throwable $exception) {
                report($exception);
                SalesLead::query()->whereKey($lead->id)->update(['sync_status' => 'sync_failed', 'last_sync_error' => 'Sinkronisasi spreadsheet gagal.', 'delivery_attempted_at' => now()]);
            }
        });
    }

    private function assertManualStatus(?string $status): void
    {
        if (blank($status)) {
            return;
        }

        $resolved = SalesLeadStatus::tryFrom(trim($status));
        if ($resolved === null) {
            throw ValidationException::withMessages(['current_status' => 'Status lead tidak dikenali.']);
        }

        if (! $resolved->isManual()) {
            throw ValidationException::withMessages([
                'current_status' => 'Status sistem tidak dapat diisi dari data lead. Gunakan aksi siklus lead yang sesuai.',
            ]);
        }
    }

    private function activityContext(SalesLead $lead): array
    {
        return ['branch_id' => $lead->branch_id, 'project_id' => $lead->project_id, 'sales_user_id' => $lead->sales_user_id];
    }

    public function spreadsheetFields(SalesLead $lead): array
    {
        if (! config('services.google_sheets.sales_lead_sync_enabled')) {
            return [];
        }

        $sheetIdentities = app(SalesSheetIdentityService::class);
        $lead->loadMissing(['branch:id,sheet_id,is_active', 'project:id,branch_id,project_name,sheet_project_name', 'sales:id,name', 'leadSource:id,name']);
        $status = $lead->current_status instanceof SalesLeadStatus
            ? $lead->current_status
            : SalesLeadStatus::fromInput($lead->current_status ?? SalesLeadStatus::NoResponse->value);

        return [
            'tanggal_lead' => $lead->lead_date?->format('Y-m-d'),
            'sumber_lead' => $lead->effective_source,
            'kanal_masuk' => $lead->platform,
            'aktivitas_lead' => $lead->campaign_name ?: $lead->campaign_id,
            'nama_konsumen' => $lead->customer_name,
            'no_hp' => $lead->phone,
            'proyek' => $sheetIdentities->projectValue($lead->project),
            'sales_pic' => $lead->sales->name,
            'status_lead' => $status->spreadsheetValue(),
            'keterangan' => $lead->notes,
            'nama_promo' => $lead->id_promo,
        ];
    }
}
