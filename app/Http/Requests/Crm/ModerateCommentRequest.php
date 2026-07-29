<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModerateCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('moderate', $this->route('comment')) ?? false;
    }

    public function rules(): array
    {
        return [
            'action' => ['sometimes', Rule::in(['hide'])],
            'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
