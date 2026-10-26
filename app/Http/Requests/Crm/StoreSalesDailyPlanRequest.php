<?php

namespace App\Http\Requests\Crm;

use App\Models\ContentItem;
use Illuminate\Foundation\Http\FormRequest;

class StoreSalesDailyPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSales() === true;
    }

    public function rules(): array
    {
        return [
            'work_date' => ['required', 'date_format:Y-m-d'],
            'activities' => ['required', 'array', 'min:1'],
            'activities.*.id' => ['nullable', 'integer'],
            'activities.*.sales_activity_category' => ['required', 'string', 'in:'.implode(',', ContentItem::SALES_ACTIVITY_CATEGORIES)],
            'activities.*.activity' => ['required', 'string', 'max:255'],
            'activities.*.location' => ['nullable', 'string', 'max:5000'],
            'activities.*.detail' => ['nullable', 'string', 'max:5000'],
            'activities.*.duration_minutes' => ['required', 'integer', 'min:1', 'max:420'],
        ];
    }
}
