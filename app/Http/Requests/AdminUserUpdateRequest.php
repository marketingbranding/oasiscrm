<?php

namespace App\Http\Requests;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('admin_user')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->email))]);
    }

    public function rules(): array
    {
        $target = $this->route('admin_user');

        return [
            'expected_updated_at' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($target)],
            'phone' => ['nullable', 'string', 'max:20'],
            'role_id' => ['required', Rule::exists(Role::class, 'id')->where('is_active', true)],
            'branch_id' => ['required', Rule::exists(Branch::class, 'id')->where('is_active', true)],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists(Branch::class, 'id')->where('is_active', true)],
            'primary_project_id' => ['nullable', 'integer', Rule::exists(LeadMaster::class, 'id')->where('is_active', true)],
            'assigned_project_ids' => ['nullable', 'array'],
            'assigned_project_ids.*' => ['integer', 'distinct', Rule::exists(LeadMaster::class, 'id')->where('is_active', true)],
            'supervisor_user_id' => ['nullable', 'integer', Rule::exists(User::class, 'id')],
        ];
    }

    public function attributes(): array
    {
        return (new AdminUserStoreRequest)->attributes();
    }

    public function messages(): array
    {
        return (new AdminUserStoreRequest)->messages();
    }
}
