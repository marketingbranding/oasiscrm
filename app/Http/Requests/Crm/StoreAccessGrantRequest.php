<?php

namespace App\Http\Requests\Crm;

use App\Models\User;
use App\Services\AccessGrantService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperadmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['expires_at' => $this->input('expires_at') ? now()->parse($this->input('expires_at')) : null]);
    }

    public function rules(): array
    {
        return [
            'grantee_user_id' => ['required', 'integer', 'exists:users,id'],
            'subject_user_id' => ['required', 'integer', 'exists:users,id'],
            'include_descendants' => ['boolean'],
            'module' => ['required', Rule::in(AccessGrantService::MODULES)],
            'can_edit' => ['boolean'],
            'expires_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'can_view' => ['required', 'boolean'],
        ];
    }

    public function grantee(): User
    {
        return User::query()->findOrFail($this->integer('grantee_user_id'));
    }

    public function subject(): User
    {
        return User::query()->findOrFail($this->integer('subject_user_id'));
    }
}
