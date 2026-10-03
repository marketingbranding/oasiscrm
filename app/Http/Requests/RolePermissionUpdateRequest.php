<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RolePermissionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('roles.assign_permissions') ?? false;
    }

    public function rules(): array
    {
        return ['permission_ids' => ['present', 'array'], 'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id']];
    }
}
