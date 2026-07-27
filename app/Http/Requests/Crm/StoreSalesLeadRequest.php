<?php

namespace App\Http\Requests\Crm;

use App\Models\SalesLead;

class StoreSalesLeadRequest extends SalesLeadRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SalesLead::class) ?? false;
    }
}
