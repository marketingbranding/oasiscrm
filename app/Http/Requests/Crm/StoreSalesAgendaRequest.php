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
            'sales_activity_category' => ['required', 'string', Rule::in(ContentItem::SALES_ACTIVITY_CATEGORIES)],
            'title' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'activity_result' => ['nullable', 'string'],
            'photos' => ['nullable', 'array', 'max:'.SalesAgendaEvidenceRules::MAX_FILES],
            'photos.*' => ['required', 'file', 'max:'.intdiv(SalesAgendaEvidenceRules::MAX_BYTES, 1024)],
        ];
    }
}
