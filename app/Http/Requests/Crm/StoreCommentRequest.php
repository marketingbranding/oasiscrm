<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'alias' => ['required', 'string', Rule::in(['sales-lead', 'planner-item', 'sales-agenda', 'expense', 'bridge-fund'])],
            'id' => ['required', 'integer', 'min:1'],
            'body' => ['required', 'string', 'max:5000', 'regex:/\S/u'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'mentioned_user_ids' => ['nullable', 'array', 'max:100'],
            'mentioned_user_ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }
}
