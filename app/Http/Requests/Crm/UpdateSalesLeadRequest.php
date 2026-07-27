<?php

namespace App\Http\Requests\Crm;

class UpdateSalesLeadRequest extends SalesLeadRequest
{
    public function authorize(): bool
    {
        return $this->lead() && ($this->user()?->can('update', $this->lead()) ?? false);
    }
}
