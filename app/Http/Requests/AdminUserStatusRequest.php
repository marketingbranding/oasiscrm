<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:500']];
    }

    public function attributes(): array
    {
        return ['reason' => 'alasan'];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'min' => ':attribute minimal :min karakter.', 'max' => ':attribute maksimal :max karakter.'];
    }
}
