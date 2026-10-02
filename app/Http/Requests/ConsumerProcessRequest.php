<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConsumerProcessRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('consumer_progress.manage')
            || $this->user()?->hasScopedPermission('consumer_progress', 'manage');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'consumer-process.slik' => ['tanggal_slik' => ['required', 'date'], 'hasil_slik' => ['required', 'string', 'max:100'], 'keputusan' => ['nullable', 'string', 'max:100'], 'keterangan' => ['nullable', 'string', 'max:5000']],
            'consumer-process.psjb' => $this->psjbRules(),
            'consumer-process.pemberkasan' => $this->bankRules(true),
            'consumer-process.bank' => $this->bankRules(false),
            'consumer-process.sp3k' => ['no_sp3k' => ['required', 'string', 'max:255'], 'sp3k_at' => ['required', 'date'], 'approved_plafond' => ['nullable', 'numeric'], 'approved_tenor' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:5000']],
            'consumer-process.ppjb' => ['tanggal_ttd_ppjb' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:5000']],
            default => [],
        };
    }

    private function psjbRules(): array
    {
        return ['tanggal_psjb' => ['required', 'date'], 'harga_unit' => ['nullable', 'numeric'], 'tanggal_utj' => ['nullable', 'date'], 'utj' => ['nullable', 'numeric'], 'tanggal_dp_klt' => ['nullable', 'date'], 'dp_all_in' => ['nullable', 'numeric'], 'nominal_cicilan' => ['nullable', 'numeric'], 'jumlah_cicilan' => ['nullable', 'integer'], 'luas_klt' => ['nullable', 'numeric'], 'harga_klt_m' => ['nullable', 'numeric'], 'harga_klt_total' => ['nullable', 'numeric'], 'cara_pembayaran' => ['nullable', 'string', 'max:100'], 'nama_promo' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'string', 'max:100'], 'keterangan' => ['nullable', 'string', 'max:5000']];
    }

    private function bankRules(bool $pemberkasan): array
    {
        return ['bank_name' => ['required', 'string', 'max:255'], 'tanggal_terima_bank' => ['nullable', 'date'], 'kc_unit' => ['nullable', 'string', 'max:255'], 'request_plafond' => ['nullable', 'numeric'], 'request_tenor' => ['nullable', 'integer'], 'tipe_pemberkasan' => [$pemberkasan ? 'nullable' : 'sometimes', 'string', 'max:100'], 'response_type' => ['nullable', 'string', 'max:100'], 'approved_plafond' => ['nullable', 'numeric'], 'approved_tenor' => ['nullable', 'integer'], 'revision_category' => ['nullable', 'string', 'max:255'], 'revision_detail' => ['nullable', 'string', 'max:5000'], 'obstacle' => ['nullable', 'string', 'max:5000'], 'status' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:5000'], 'attempt_key' => ['nullable', 'string', 'max:255']];
    }
}
