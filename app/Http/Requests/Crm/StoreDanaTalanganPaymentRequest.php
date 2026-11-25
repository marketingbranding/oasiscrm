<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class StoreDanaTalanganPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('dana_talangan')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $record = $this->route('danaTalangan');

        $this->merge([
            'form_mode' => 'payment',
            'payment_record_id' => $record?->getKey(),
        ]);
    }

    public function rules(): array
    {
        return [
            'paid_at' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:5000'],
            'is_verified' => ['nullable', 'boolean'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function attributes(): array
    {
        return [
            'paid_at' => 'tanggal pembayaran',
            'amount' => 'nominal pembayaran',
            'evidence' => 'bukti pembayaran',
        ];
    }

    public function messages(): array
    {
        return [
            'paid_at.required' => 'Tanggal pembayaran wajib diisi.',
            'paid_at.date' => 'Tanggal pembayaran tidak valid.',
            'amount.required' => 'Nominal pembayaran wajib diisi.',
            'amount.numeric' => 'Nominal pembayaran harus berupa angka.',
            'amount.gt' => 'Nominal pembayaran harus lebih besar dari nol.',
            'amount.decimal' => 'Nominal pembayaran maksimal memiliki dua angka desimal.',
            'evidence.mimes' => 'Bukti pembayaran harus berupa JPG, PNG, atau PDF.',
            'evidence.max' => 'Ukuran bukti pembayaran maksimal 5 MB.',
        ];
    }
}
