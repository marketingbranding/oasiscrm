<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnableOperationalMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('system.maintenance_manage') === true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:2000'],
            'estimated_end_at' => ['nullable', 'date', 'after:now'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'confirmation' => ['required', Rule::in(['AKTIFKAN MAINTENANCE'])],
            'maintenance_action' => ['required', Rule::in(['enable'])],
        ];
    }

    public function messages(): array
    {
        return [
            'estimated_end_at.after' => 'Perkiraan selesai harus berada di masa mendatang.',
            'confirmation.in' => 'Ketik AKTIFKAN MAINTENANCE untuk melanjutkan.',
        ];
    }
}
