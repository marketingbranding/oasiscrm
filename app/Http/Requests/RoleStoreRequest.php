<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RoleStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('roles.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'alpha_dash', 'max:80', 'unique:roles,slug'],
            'description' => ['nullable', 'string', 'max:1000'],
            'authority_level' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
