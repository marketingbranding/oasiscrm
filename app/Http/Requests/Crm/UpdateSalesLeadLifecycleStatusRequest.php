<?php

namespace App\Http\Requests\Crm;

use App\Enums\SalesLeadStatus;
use App\Support\FeatureFlag;
use Illuminate\Validation\Rule;

class UpdateSalesLeadLifecycleStatusRequest extends SalesLeadLifecycleRequest
{
    public function authorize(): bool
    {
        return $this->lead() !== null && ($this->user()?->can('updateLifecycleStatus', $this->lead()) ?? false);
    }

    public function rules(): array
    {
        if (FeatureFlag::enabled('lead_stages_v3') && $this->input('status') === 'gugur') {
            return $this->operationRules() + [
                'status' => ['required', 'in:gugur'],
                'reason' => ['required', 'string', 'max:255'],
                'project_id' => ['prohibited'],
            ];
        }

        return $this->operationRules() + [
            'status' => ['required', Rule::in(array_map(fn (SalesLeadStatus $status) => $status->value, SalesLeadStatus::MANUAL))],
            'project_id' => ['prohibited'],
        ];
    }
}
