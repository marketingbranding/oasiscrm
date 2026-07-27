<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleSalesAgendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scheduled_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_without:duration_minutes', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'required_without:end_time', 'integer', 'min:1', 'max:1440'],
            'expected_updated_at' => ['required', 'string', 'max:40'],
        ];
    }
}
