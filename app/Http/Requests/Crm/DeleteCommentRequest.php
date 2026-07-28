<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class DeleteCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delete', $this->route('comment')) ?? false;
    }

    public function rules(): array
    {
        return ['expected_lock_version' => ['required', 'integer', 'min:0']];
    }
}
