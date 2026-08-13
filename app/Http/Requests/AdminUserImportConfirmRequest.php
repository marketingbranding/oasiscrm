<?php

namespace App\Http\Requests;

use App\Models\UserImportBatch;
use Illuminate\Foundation\Http\FormRequest;

class AdminUserImportConfirmRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'activation_mode' => $this->input('activation_mode', UserImportBatch::ACTIVATION_MODE_INVITATION),
        ]);
    }

    public function authorize(): bool
    {
        $batch = UserImportBatch::find($this->integer('batch_id'));
        $actor = $this->user();

        return $batch !== null
            && ($actor?->can('view', $batch) ?? false)
            && ($this->input('activation_mode') !== UserImportBatch::ACTIVATION_MODE_DIRECT || $actor?->isSuperadmin());
    }

    public function rules(): array
    {
        return [
            'batch_id' => ['required', 'integer', 'exists:user_import_batches,id'],
            'expected_updated_at' => ['required', 'date'],
            'send_invitations' => ['nullable', 'boolean'],
            'activation_mode' => ['required', 'in:'.implode(',', UserImportBatch::ACTIVATION_MODES)],
        ];
    }
}
