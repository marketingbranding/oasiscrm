<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DisableOperationalMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('system.maintenance_manage') === true;
    }

    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            'confirmation' => ['required', Rule::in(['NONAKTIFKAN MAINTENANCE'])],
            'maintenance_action' => ['required', Rule::in(['disable'])],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.in' => 'Ketik NONAKTIFKAN MAINTENANCE untuk melanjutkan.',
        ];
    }
}
