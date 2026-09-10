<?php

namespace App\Http\Requests\Crm;

class MarkSalesLeadUtjRequest extends SalesLeadLifecycleRequest
{
    public function authorize(): bool
    {
        return $this->lead() !== null && ($this->user()?->can('markUtjDirect', $this->lead()) ?? false);
    }

    public function rules(): array
    {
        return $this->operationRules();
    }
}
