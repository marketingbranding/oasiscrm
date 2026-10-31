<?php

namespace App\Http\Requests\Crm;

use App\Support\SalesAgendaEvidenceRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreSalesAgendaEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['photo' => ['required', 'file', 'max:'.intdiv(SalesAgendaEvidenceRules::MAX_BYTES, 1024)]];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'Pilih foto bukti terlebih dahulu.',
            'photo.file' => 'File bukti harus berupa gambar yang valid.',
            'photo.uploaded' => 'Ukuran foto terlalu besar atau gagal diunggah.',
            'photo.max' => 'Ukuran foto terlalu besar. Maksimal 10 MB.',
        ];
    }
}
