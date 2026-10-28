<?php

namespace App\Http\Requests\Crm;

use App\Models\ContentItem;
use App\Support\SalesAgendaEvidenceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesAgendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user()?->isSales() === true) {
            return $this->user()->can('createSalesAgenda', ContentItem::class);
        }

        return $this->user()?->hasPrimaryRole('sales_coordinator') === true
            && $this->user()->hasPermission('sales_pocketbook.manage_team');
    }

    public function rules(): array
    {
        return [
            'sales_user_id' => [Rule::requiredIf(fn (): bool => $this->user()?->hasPrimaryRole('sales_coordinator') === true), 'nullable', 'integer', 'exists:users,id'],
            'project_id' => [Rule::requiredIf(fn (): bool => $this->user()?->hasPrimaryRole('sales_coordinator') === true), 'nullable', 'integer', 'exists:lead_master,id'],
            'scheduled_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time', 'after:start_time'],
            'sales_activity_category' => ['required', 'string', Rule::in(ContentItem::SALES_ACTIVITY_CATEGORIES)],
            'title' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'activity_result' => ['nullable', 'string'],
            'photos' => ['nullable', 'array', 'max:'.SalesAgendaEvidenceRules::MAX_FILES],
            'photos.*' => ['required', 'file', 'max:'.intdiv(SalesAgendaEvidenceRules::MAX_BYTES, 1024)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'start_time' => filled($this->input('start_time')) ? substr((string) $this->input('start_time'), 0, 5) : null,
            'end_time' => filled($this->input('end_time')) ? substr((string) $this->input('end_time'), 0, 5) : null,
        ]);
    }
}
