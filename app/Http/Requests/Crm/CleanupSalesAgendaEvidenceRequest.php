<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class CleanupSalesAgendaEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500'], 'confirmation' => ['accepted']];
    }
}
