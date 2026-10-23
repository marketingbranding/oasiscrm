<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class CompleteSalesDailyPlanItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSales() === true;
    }

    public function rules(): array
    {
        return ['activity_result' => ['required', 'string', 'max:5000']];
    }
}
