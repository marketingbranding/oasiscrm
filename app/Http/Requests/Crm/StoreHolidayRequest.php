<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class StoreHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperadmin() === true
            && $this->user()?->hasPermission('system.settings_manage') === true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }
}
