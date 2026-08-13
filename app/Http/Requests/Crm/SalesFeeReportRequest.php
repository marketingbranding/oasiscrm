<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class SalesFeeReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPrimaryRole('admin') === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'date_from' => $this->input('date_from', today()->startOfMonth()->toDateString()),
            'date_to' => $this->input('date_to', today()->toDateString()),
        ]);
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'project_id' => ['nullable', 'integer', 'exists:lead_master,id'],
            'coordinator_id' => ['nullable', 'integer', 'exists:users,id'],
            'sales_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
