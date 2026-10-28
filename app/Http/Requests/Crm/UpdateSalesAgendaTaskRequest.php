<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSalesAgendaTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'is_completed' => ['required', 'boolean'],
            'expected_updated_at' => ['required', 'string', 'max:40'],
        ];
    }
}
