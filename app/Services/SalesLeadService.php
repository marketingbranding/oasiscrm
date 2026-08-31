<?php

namespace App\Services;

use App\Enums\SalesLeadStatus;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SalesLeadService
{
    public function __construct(
        private readonly PhoneNormalizationService $phones,
        private readonly SalesLeadLifecycleService $lifecycle,
        private readonly ?SalesLeadBridgeModeService $bridgeModes = null,
        private readonly ?SalesLeadBridgeService $bridge = null,
    ) {}

    public function create(array $data, User $actor): SalesLead
    {
        $lead = DB::transaction(function () use ($data, $actor): SalesLead {
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
        $this->schedulePush($lead, $actor);

        return $lead;
    }

    public function update(SalesLead $lead, array $data, User $actor): SalesLead
    {
        $updated = DB::transaction(function () use ($lead, $data, $actor): SalesLead {
            $locked = SalesLead::query()->lockForUpdate()->findOrFail($lead->id);
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
