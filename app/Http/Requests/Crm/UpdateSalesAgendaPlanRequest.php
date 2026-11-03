<?php

namespace App\Http\Requests\Crm;

use App\Models\ContentItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSalesAgendaPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateSalesAgenda', $this->route('agenda')) === true;
    }

    public function rules(): array
    {
        return [
            'sales_activity_category' => ['required', 'string', Rule::in(ContentItem::SALES_ACTIVITY_CATEGORIES)],
            'title' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'location' => ['nullable', 'string', 'max:255'],
            'expected_updated_at' => ['required', 'string', 'max:40'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'start_time' => filled($this->input('start_time')) ? substr((string) $this->input('start_time'), 0, 5) : null,
            'end_time' => filled($this->input('end_time')) ? substr((string) $this->input('end_time'), 0, 5) : null,
        ]);
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = CarbonImmutable::createFromFormat('H:i', (string) $this->input('start_time'));
            $end = CarbonImmutable::createFromFormat('H:i', (string) $this->input('end_time'));
            $duration = $start->diffInMinutes($end);

            if ($duration < 5 || $duration % 5 !== 0) {
                $validator->errors()->add('end_time', 'Durasi agenda harus minimal 5 menit dan kelipatan 5 menit.');
            }
        }];
    }
}
