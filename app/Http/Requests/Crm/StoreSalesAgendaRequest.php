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
        return $this->user()?->isSales() === true;
    }

    public function rules(): array
    {
        return [
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
