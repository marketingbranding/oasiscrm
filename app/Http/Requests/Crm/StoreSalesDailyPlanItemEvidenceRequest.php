<?php

namespace App\Http\Requests\Crm;

use App\Support\SalesAgendaEvidenceRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreSalesDailyPlanItemEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSales() === true;
    }

    public function rules(): array
    {
        return ['photo' => ['required', 'file', 'max:'.intdiv(SalesAgendaEvidenceRules::MAX_BYTES, 1024)]];
    }
}
