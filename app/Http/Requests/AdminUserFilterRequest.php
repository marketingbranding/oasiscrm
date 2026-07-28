<?php

namespace App\Http\Requests;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUserFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', User::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'account_status' => ['nullable', Rule::enum(AccountStatus::class)],
            'role_id' => ['nullable', 'integer'], 'branch_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'], 'supervisor_user_id' => ['nullable', 'integer'],
            'invitation_status' => ['nullable', Rule::in(['draft', 'usable', 'expired', 'accepted', 'revoked'])],
            'sort' => ['nullable', Rule::in(['name', 'email', 'last_login_at', 'account_status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    public function messages(): array
    {
        return ['in' => 'Nilai filter yang dipilih tidak valid.', 'enum' => 'Status akun yang dipilih tidak valid.', 'integer' => 'Nilai filter harus berupa angka.'];
    }
}
