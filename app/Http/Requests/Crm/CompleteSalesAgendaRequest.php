<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class CompleteSalesAgendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'activity_result' => ['required', 'string'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'expected_updated_at' => ['required', 'string', 'max:40'],
        ];
    }
}
