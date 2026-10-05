<?php

namespace App\Http\Requests;

use App\Models\OrganizationUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizationUnitMoveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('organization.move_user') ?? false;
    }

    public function rules(): array
    {
        return [
            'organization_unit_id' => ['required', 'integer', Rule::exists(OrganizationUnit::class, 'id')->where('is_active', true)],
            'expected_organization_unit_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
