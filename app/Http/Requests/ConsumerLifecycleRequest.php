<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConsumerLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('consumer_progress.manage')
            || $this->user()?->hasScopedPermission('consumer_progress', 'manage');
    }

    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'consumer-applications.pindah-kavling' => [
                'target_kavling_id' => ['required', 'integer', 'exists:kavlings,id'],
            ],
            'consumer-applications.ganti-bank' => $this->bankRules(),
            'consumer-applications.ganti-konsumen' => [
                'replacement_customer_id' => ['required', 'integer', 'exists:customers,id'],
                'target_kavling_id' => ['nullable', 'integer', 'exists:kavlings,id'],
            ],
            'consumer-applications.ready100' => [
                'ready_100_at' => ['required', 'date'],
                'status' => ['nullable', 'string', 'max:100'],
                'source' => ['nullable', 'string', 'max:100'],
                'source_id' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string', 'max:5000'],
                'metadata' => ['nullable', 'array'],
            ],
            'consumer-applications.akad' => [
                'tanggal_akad' => ['required', 'date'],
                'kualitas_akad' => ['nullable', 'string', 'max:100'],
                'status_bangunan' => ['nullable', 'string', 'max:100'],
                'status_dp_konsumen' => ['nullable', 'string', 'max:100'],
                'status_utilitas' => ['nullable', 'string', 'max:100'],
                'status_konsumen' => ['nullable', 'string', 'max:100'],
                'keterangan_terlambat' => ['nullable', 'string', 'max:5000'],
                'no_ppjb_akad' => ['nullable', 'string', 'max:255'],
            ],
            'consumer-applications.bast' => [
                'tanggal_bast' => ['required', 'date'],
                'no_bast' => ['nullable', 'string', 'max:255'],
                'status' => ['nullable', 'string', 'max:100'],
                'notes' => ['nullable', 'string', 'max:5000'],
            ],
            default => [],
        };
    }

    /** @return array<string, list<string>> */
    private function bankRules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:255'],
            'attempt_key' => ['nullable', 'string', 'max:255'],
            'tanggal_terima_bank' => ['nullable', 'date'],
            'id_berkas' => ['nullable', 'string', 'max:255'],
            'no_sp3k' => ['nullable', 'string', 'max:255'],
            'kc_unit' => ['nullable', 'string', 'max:255'],
            'request_plafond' => ['nullable', 'numeric'],
            'request_tenor' => ['nullable', 'integer'],
            'approved_plafond' => ['nullable', 'numeric'],
            'approved_tenor' => ['nullable', 'integer'],
            'response_type' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:100'],
            'revision_category' => ['nullable', 'string', 'max:255'],
            'revision_detail' => ['nullable', 'string', 'max:5000'],
            'obstacle' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'submitted_at' => ['nullable', 'date'],
            'verified_at' => ['nullable', 'date'],
            'sp3k_at' => ['nullable', 'date'],
            'rejected_at' => ['nullable', 'date'],
            'rejection_reason' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
