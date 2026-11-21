<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class TransferSalesLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('sales_pocketbook.manage_team') ?? false;
    }

    public function rules(): array
    {
        return [
            'lead_ids' => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['integer', 'distinct', 'exists:sales_leads,id'],
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
