<?php

namespace App\Http\Requests\Crm;

use App\Models\ContentItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesDailyPlanAgendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('daily_plan')) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'sales_activity_category' => ['required', Rule::in(ContentItem::SALES_ACTIVITY_CATEGORIES)],
            'location' => ['nullable', 'string', 'max:1000'],
            'start_time' => [Rule::requiredIf(fn (): bool => $this->route('daily_plan')?->isWeekend() === true), 'nullable', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'task_detail' => ['nullable', 'string', 'max:5000'],
            'sales_project_id' => ['nullable', 'integer', 'exists:lead_master,id'],
            'expected_updated_at' => ['required', 'string', 'max:60'],
        ];
    }
}
