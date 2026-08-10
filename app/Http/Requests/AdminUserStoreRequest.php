<?php

namespace App\Http\Requests;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminUserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return ($actor?->can('create', User::class) ?? false)
            && ($this->input('provisioning_mode', 'invitation') !== 'direct' || $actor->isSuperadmin());
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->email)),
            'provisioning_mode' => $this->input('provisioning_mode', 'invitation'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'phone' => ['nullable', 'string', 'max:20'],
            'role_id' => ['required', Rule::exists(Role::class, 'id')->where('is_active', true)],
            'branch_id' => ['required', Rule::exists(Branch::class, 'id')->where('is_active', true)],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists(Branch::class, 'id')->where('is_active', true)],
            'primary_project_id' => ['nullable', 'integer', Rule::exists(LeadMaster::class, 'id')->where('is_active', true)],
            'assigned_project_ids' => ['nullable', 'array'],
            'assigned_project_ids.*' => ['integer', 'distinct', Rule::exists(LeadMaster::class, 'id')->where('is_active', true)],
            'supervisor_user_id' => ['nullable', 'integer', Rule::exists(User::class, 'id')],
            'provisioning_mode' => ['required', Rule::in(['invitation', 'direct'])],
            'temporary_password' => ['exclude_unless:provisioning_mode,direct', 'required_if:provisioning_mode,direct', 'nullable', 'confirmed', Password::min(8)->letters()->numbers()],
            'send_immediately' => ['nullable', 'boolean', Rule::prohibitedIf(fn () => $this->input('provisioning_mode') === 'direct')],
            'submit_action' => [
                'required',
                Rule::when($this->input('provisioning_mode') === 'direct', Rule::in(['activate']), Rule::in(['draft', 'send'])),
            ],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nama lengkap', 'role_id' => 'peran', 'branch_id' => 'cabang utama', 'branch_ids' => 'cabang tambahan', 'assigned_project_ids' => 'proyek tambahan', 'primary_project_id' => 'proyek utama', 'supervisor_user_id' => 'atasan langsung'];
    }

    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'email' => ':attribute harus berupa alamat email yang valid.',
            'unique' => ':attribute sudah digunakan oleh akun lain.',
            'exists' => ':attribute yang dipilih tidak aktif atau tidak ditemukan.',
            'distinct' => ':attribute tidak boleh berisi pilihan ganda.',
        ];
    }
}
