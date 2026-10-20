<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class ReorderSalesDailyPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('daily_plan')) ?? false;
    }

    public function rules(): array
    {
        return [
            'agenda_ids' => ['required', 'array', 'min:1'],
            'agenda_ids.*' => ['required', 'integer', 'distinct', 'exists:content_items,id'],
            'expected_updated_at' => ['required', 'string', 'max:60'],
        ];
    }
}
