<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConsumerEntryRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'], 'nik' => ['nullable', 'digits:16'], 'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'], 'date_of_birth' => ['nullable', 'date'], 'occupation' => ['nullable', 'string', 'max:255'],
            'occupation_detail' => ['nullable', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:5000'],
            'kelurahan' => ['nullable', 'string', 'max:255'], 'kecamatan' => ['nullable', 'string', 'max:255'], 'kabupaten_kota' => ['nullable', 'string', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'], 'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'], 'project_id' => ['required', 'integer', 'exists:lead_master,id'],
            'sales_user_id' => ['nullable', 'integer', 'exists:users,id'], 'kavling_id' => ['nullable', 'integer', 'exists:kavlings,id'],
            'promo_id' => ['nullable', 'integer', 'exists:promos,id'], 'payment_method' => ['nullable', 'in:kpr,cash,cash_bertahap,other'],
            'entry_mode' => ['nullable', 'in:new,historical,migration'], 'current_process' => ['nullable', 'in:data_konsumen,psjb,slik,pemberkasan,proses_bank,sp3k,ppjb,akad,bast,garansi,selesai'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
