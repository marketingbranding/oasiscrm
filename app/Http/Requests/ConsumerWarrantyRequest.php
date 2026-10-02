<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConsumerWarrantyRequest extends FormRequest
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
        return ['consumer_bast_record_id' => ['nullable', 'integer', 'exists:consumer_bast_records,id'], 'status_komplain' => ['required', 'in:Belum Dipilih,Ada Komplain,Tidak Ada Komplain'], 'tgl_sales_ke_sam' => ['nullable', 'date'], 'tgl_sam_ke_sat' => ['nullable', 'date'], 'tgl_sat_ke_sam' => ['nullable', 'date'], 'tgl_sam_ke_sales' => ['nullable', 'date'], 'tgl_sales_ke_kons' => ['nullable', 'date'], 'detail_garansi' => ['nullable', 'string', 'max:10000'], 'tanggal_selesai' => ['nullable', 'date'], 'status_garansi' => ['required', 'in:Belum Dipilih,Proses,Tidak Ada Komplain,Selesai']];
    }
}
