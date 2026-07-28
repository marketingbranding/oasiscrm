<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('comment')) ?? false;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000', 'regex:/\S/u'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'mentioned_user_ids' => ['nullable', 'array', 'max:100'],
            'mentioned_user_ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }
}
