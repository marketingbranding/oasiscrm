<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreLeadDailyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lead_event_id' => 'required|exists:lead_events,id',
            'branch_id' => 'required|exists:branches,id',
            'date' => 'required|date',
            'leads_count' => 'required|integer|min:0',
        ];
    }
}
