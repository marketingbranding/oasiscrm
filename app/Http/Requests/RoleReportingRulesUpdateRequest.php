<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RoleReportingRulesUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('organization.configure_rules') ?? false;
    }

    public function rules(): array
    {
        return [
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.parent_role_id' => ['required', 'integer', 'exists:roles,id'],
            'rules.*.child_role_id' => ['required', 'integer', 'exists:roles,id'],
            'rules.*.is_allowed' => ['required', 'boolean'],
        ];
    }
}
