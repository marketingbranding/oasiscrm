<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class ReviseSalesDailyPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPrimaryRole('sales_coordinator') === true;
    }

    public function rules(): array
    {
        return [
            'activities' => ['required', 'array', 'min:1'],
            'activities.*.id' => ['nullable', 'integer'],
            'activities.*.activity' => ['required', 'string', 'max:255'],
            'activities.*.detail' => ['nullable', 'string', 'max:5000'],
            'activities.*.duration_minutes' => ['required', 'integer', 'min:1', 'max:420'],
        ];
    }
}
