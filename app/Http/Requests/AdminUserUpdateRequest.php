<?php

namespace App\Http\Requests;

use App\Enums\AccountStatus;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAdministrationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'coordinator_sales_ids' => ['nullable', 'array'],
            'coordinator_sales_ids.*' => ['integer', 'distinct', Rule::exists(User::class, 'id')],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('coordinator_sales_ids')) {
                return;
            }

            $target = $this->route('admin_user');
            $role = Role::find($this->input('role_id'));
            $ids = collect($this->input('coordinator_sales_ids', []))->map(fn ($id) => (int) $id)->unique();
            if ($ids->isNotEmpty() && $role?->slug !== 'sales_coordinator') {
                $validator->errors()->add('coordinator_sales_ids', 'Tim Sales hanya dapat dipilih untuk Koordinator Sales.');

                return;
            }

            if ($role?->slug !== 'sales_coordinator') {
                return;
            }

            $allowed = app(UserAdministrationService::class)->visibleQuery($this->user())
                ->where('account_status', AccountStatus::Active->value)
                ->whereHas('role', fn ($query) => $query->where('slug', 'sales'))
                ->whereIn('users.id', $ids)
                ->count();
            if ($allowed !== $ids->count()) {
                $validator->errors()->add('coordinator_sales_ids', 'Pilihan Sales tidak aktif, bukan Sales utama, atau di luar akses Anda.');
            }
        }];
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
