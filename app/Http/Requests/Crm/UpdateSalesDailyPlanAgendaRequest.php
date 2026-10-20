<?php

namespace App\Http\Requests\Crm;

use App\Models\ContentItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSalesDailyPlanAgendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('daily_plan')) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'sales_activity_category' => ['required', Rule::in(ContentItem::SALES_ACTIVITY_CATEGORIES)],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:420'],
            'task_detail' => ['required', 'string', 'max:5000'],
            'expected_updated_at' => ['required', 'string', 'max:60'],
        ];
    }
}
