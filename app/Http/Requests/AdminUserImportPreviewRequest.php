<?php

namespace App\Http\Requests;

use App\Models\UserImportBatch;
use Illuminate\Foundation\Http\FormRequest;

class AdminUserImportPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', UserImportBatch::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:5120',
                'extensions:xlsx',
                'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream',
            ],
            'send_invitations' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'File XLSX wajib dipilih.',
            'file.file' => 'Berkas unggahan tidak valid.',
            'file.max' => 'Ukuran file XLSX maksimal 5 MB.',
            'file.extensions' => 'File harus menggunakan ekstensi .xlsx. CSV tidak didukung.',
            'file.mimes' => 'File harus berupa workbook XLSX yang valid. CSV tidak didukung.',
            'file.mimetypes' => 'Tipe file harus berupa workbook XLSX yang valid.',
            'send_invitations.boolean' => 'Pilihan pengiriman undangan tidak valid.',
        ];
    }
}
