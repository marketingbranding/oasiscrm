<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class DeleteSalesAgendaPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateSalesAgenda', $this->route('agenda')) === true;
    }

    public function rules(): array
    {
        return [
            'expected_updated_at' => ['required', 'string', 'max:40'],
        ];
    }
}
