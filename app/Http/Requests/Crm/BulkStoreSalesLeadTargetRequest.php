<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class BulkStoreSalesLeadTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperadmin() === true;
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
            'target_leads' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'month.required' => 'Bulan target wajib dipilih.',
            'month.date_format' => 'Bulan target harus berformat YYYY-MM.',
            'target_leads.required' => 'Target Lead wajib diisi.',
            'target_leads.min' => 'Target Lead minimal 1.',
            'target_leads.max' => 'Target Lead maksimal 100.000.',
        ];
    }
}
