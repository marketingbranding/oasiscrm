<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreFeedbackReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim($this->title ?? ''),
            'description' => trim($this->description ?? ''),
        ]);
    }

    public function rules(): array
    {
        $user = Auth::user();
        return [
            'type' => 'required|string|in:masukan,bug',
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'branch_id' => $user->canViewAllBranches() ? 'nullable|exists:branches,id' : 'nullable',
        ];
    }
}
