<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

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
            'title' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'activity_result' => ['nullable', 'string'],
        ];
    }
}
