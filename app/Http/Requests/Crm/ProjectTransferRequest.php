<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperadmin() === true && ! (bool) session('impersonating_user_id');
    }

    public function rules(): array
    {
        return [
            'source_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'target_branch_id' => ['required', 'integer', 'different:source_branch_id', 'exists:branches,id'],
            'project_ids' => ['required', 'array', 'min:1'],
            'project_ids.*' => ['integer', 'distinct', 'exists:lead_master,id'],
            'user_mapping' => ['array'],
            'user_mapping.*' => [Rule::in(['pindah', 'tetap', 'keduanya'])],
            'confirmation' => ['nullable', 'string'],
            'target_branch_name' => ['nullable', 'string'],
        ];
    }
}
