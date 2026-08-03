<?php

namespace App\Http\Requests\Crm;

use App\Enums\SalesLeadStatus;
use Illuminate\Validation\Rule;

class UpdateSalesLeadLifecycleStatusRequest extends SalesLeadLifecycleRequest
{
    public function authorize(): bool
    {
        return $this->lead() !== null && ($this->user()?->can('updateLifecycleStatus', $this->lead()) ?? false);
    }

    public function rules(): array
    {
        return $this->operationRules() + [
            'status' => ['required', Rule::in(array_map(fn (SalesLeadStatus $status) => $status->value, SalesLeadStatus::MANUAL))],
            'project_id' => ['prohibited'],
        ];
    }
}
