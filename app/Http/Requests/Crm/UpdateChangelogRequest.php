<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChangelogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => 'nullable|string|max:50',
            'category' => 'required|string|in:added,fixed,changed,removed',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
        ];
    }
}
