<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SalesOnboardingDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperadmin() === true
            && $this->user()->hasPermission('users.approve_sales');
    }

    public function rules(): array
    {
        return [
            'decision_token' => ['required', 'string', 'uuid'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
