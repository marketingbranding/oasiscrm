<?php

namespace App\Services;

use App\Enums\SalesLeadStatus;
use App\Models\LeadSource;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesLeadService
{
    public function __construct(
        private readonly PhoneNormalizationService $phones,
        private readonly SalesLeadLifecycleService $lifecycle,
    ) {}

    public function create(array $data, User $actor): SalesLead
    {
        return DB::transaction(function () use ($data, $actor): SalesLead {
            $data['normalized_phone'] = $this->phones->normalize($data['phone'] ?? null);
            $data['source_name_snapshot'] = isset($data['lead_source_id']) ? LeadSource::find($data['lead_source_id'])?->name : null;
            $data['source'] = filled($data['source'] ?? null) ? $data['source'] : $this->legacySource($data['source_name_snapshot']);
            $data['current_status'] ??= SalesLeadStatus::NoResponse->value;
            $data['current_status_changed_at'] = now();
            $data['current_status_source'] = 'manual';
            $data['current_status_source_id'] = (string) $actor->id;
            $data['external_sync_id'] = (string) Str::uuid();
            $data['created_by'] = $actor->id;
            $data['updated_by'] = $actor->id;
            $lead = SalesLead::create($data);

            $remote = $this->writer()->append($lead, 'lead', $this->spreadsheetFields($lead), $lead->external_sync_id);
            $externalLeadId = trim((string) ($remote->rowValues['id_lead'] ?? ''));
            if ($externalLeadId !== '') {
                $duplicate = SalesLead::query()
                    ->where('branch_id', $lead->branch_id)
                    ->where('external_lead_id', $externalLeadId)
                    ->where('id', '!=', $lead->id)
                    ->exists();
                if ($duplicate) {
                    throw new \DomainException('ID lead dari spreadsheet sudah digunakan pada cabang ini.');
                }
                $lead->update(['external_lead_id' => $externalLeadId]);
            }
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
    }

    public function update(SalesLead $lead, array $data, User $actor): SalesLead
    {
        return DB::transaction(function () use ($lead, $data, $actor): SalesLead {
            $locked = SalesLead::query()->lockForUpdate()->findOrFail($lead->id);
            if (blank($data['current_status'] ?? null)) {
                unset($data['current_status']);
            }
            if ($locked->external_sync_id && (int) $locked->branch_id !== (int) $data['branch_id']) {
                throw new \DomainException('Lead yang sudah tersinkron tidak dapat dipindahkan ke cabang lain.');
            }

            $changedFields = array_keys(array_filter($data, fn ($value, $key) => $locked->{$key} != $value, ARRAY_FILTER_USE_BOTH));
            $data['normalized_phone'] = $this->phones->normalize($data['phone'] ?? null);
            if (isset($data['lead_source_id']) && (int) $data['lead_source_id'] !== (int) $locked->lead_source_id) {
                $data['source_name_snapshot'] = LeadSource::find($data['lead_source_id'])?->name;
            }
            $data['source'] = filled($data['source'] ?? null) ? $data['source'] : $this->legacySource($data['source_name_snapshot'] ?? $locked->source_name_snapshot);
            $previousStatus = $locked->current_status;
            $statusChanged = isset($data['current_status']) && $previousStatus !== SalesLeadStatus::fromInput($data['current_status']);
            if ($statusChanged) {
                $data['current_status_changed_at'] = now();
                $data['current_status_source'] = 'manual';
                $data['current_status_source_id'] = (string) $actor->id;
            }
            $data['updated_by'] = $actor->id;

            $remote = $locked->replicate();
            $remote->forceFill($data);
            if ($locked->external_sync_id) {
                $this->writer()->updateBySyncId($locked, 'lead', $locked->external_sync_id, $this->spreadsheetFields($remote));
            }
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

    private function activityContext(SalesLead $lead): array
    {
        return ['branch_id' => $lead->branch_id, 'project_id' => $lead->project_id, 'sales_user_id' => $lead->sales_user_id];
    }

    public function spreadsheetFields(SalesLead $lead): array
    {
        $lead->loadMissing(['project:id,project_name', 'sales:id,name', 'leadSource:id,name']);
        $status = $lead->current_status instanceof SalesLeadStatus
            ? $lead->current_status
            : SalesLeadStatus::fromInput($lead->current_status ?? SalesLeadStatus::NoResponse->value);

        return [
            'tanggal_lead' => $lead->lead_date?->format('Y-m-d'),
            'sumber' => $lead->source ?: ($lead->source_name_snapshot ?: $lead->leadSource?->name),
            'platform' => $lead->platform,
            'campaign' => $lead->campaign_name ?: $lead->campaign_id,
            'nama_konsumen' => $lead->customer_name,
            'no_hp' => $lead->phone,
            'proyek' => $lead->project?->project_name,
            'sales_pic' => $lead->sales?->name,
            'status_lead' => $status->spreadsheetValue(),
            'keterangan' => $lead->notes,
            'id_promo' => $lead->id_promo,
        ];
    }

    private function writer(): SalesLeadSpreadsheetWriter
    {
        return app(SalesLeadSpreadsheetWriter::class);
    }

    private function legacySource(?string $source): ?string
    {
        return $source === 'Iklan Pusat' ? 'Lead Cabang' : $source;
    }
}
