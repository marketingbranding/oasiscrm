<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarisonMigrationConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperadmin() === true;
    }

    public function rules(): array
    {
        return ['preview_hash' => ['required', 'string', 'size:64'], 'preview_version' => ['required', 'integer', 'min:1']];
    }
}
