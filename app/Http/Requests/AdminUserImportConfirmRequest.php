<?php

namespace App\Http\Requests;

use App\Models\UserImportBatch;
use Illuminate\Foundation\Http\FormRequest;

class AdminUserImportConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        $batch = UserImportBatch::find($this->integer('batch_id'));

        return $batch !== null && ($this->user()?->can('view', $batch) ?? false);
    }

    public function rules(): array
    {
        return [
            'batch_id' => ['required', 'integer', 'exists:user_import_batches,id'],
            'expected_updated_at' => ['required', 'date'],
            'send_invitations' => ['nullable', 'boolean'],
        ];
    }
}
