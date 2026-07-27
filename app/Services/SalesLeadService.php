<?php

namespace App\Services;

use App\Models\LeadSource;
use App\Models\SalesLead;
use App\Models\User;

class SalesLeadService
{
    public function __construct(private readonly PhoneNormalizationService $phones) {}

    public function create(array $data, User $actor): SalesLead
    {
        $data['normalized_phone'] = $this->phones->normalize($data['phone'] ?? null);
        $data['source_name_snapshot'] = isset($data['lead_source_id']) ? LeadSource::find($data['lead_source_id'])?->name : null;
        $data['created_by'] = $actor->id;
        $data['updated_by'] = $actor->id;
        $lead = SalesLead::create($data);
        $lead->logSalesActivity('created', $this->activityContext($lead));

        return $lead;
    }

    public function update(SalesLead $lead, array $data, User $actor): SalesLead
    {
        $changedFields = array_keys(array_filter($data, fn ($value, $key) => $lead->{$key} != $value, ARRAY_FILTER_USE_BOTH));
        $data['normalized_phone'] = $this->phones->normalize($data['phone'] ?? null);
        $data['source_name_snapshot'] = isset($data['lead_source_id']) ? LeadSource::find($data['lead_source_id'])?->name : null;
        $data['updated_by'] = $actor->id;
        $lead->update($data);
        $lead->logSalesActivity('updated', $this->activityContext($lead) + ['changed_fields' => $changedFields]);

        return $lead->fresh();
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
}
