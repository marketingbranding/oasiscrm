<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrganizationRemoveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('organization.move_user') ?? false;
    }

    public function rules(): array
    {
        return [
            'expected_organization_unit_id' => ['nullable', 'integer', 'min:1'],
            'expected_assignment_id' => ['nullable', 'integer', 'min:1'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
