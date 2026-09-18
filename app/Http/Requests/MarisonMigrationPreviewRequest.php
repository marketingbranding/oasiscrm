<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarisonMigrationPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperadmin() === true;
    }

    public function rules(): array
    {
        return ['package' => ['required', 'file', 'max:20480', 'extensions:json', 'mimetypes:application/json,text/json,text/plain,application/octet-stream']];
    }

    public function messages(): array
    {
        return ['package.required' => 'Paket JSON wajib dipilih.', 'package.max' => 'Paket JSON maksimal 20 MB.', 'package.extensions' => 'Paket harus berupa file JSON.'];
    }
}
