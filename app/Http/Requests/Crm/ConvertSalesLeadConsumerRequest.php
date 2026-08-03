<?php

namespace App\Http\Requests\Crm;

use Closure;
use Illuminate\Validation\Rule;

class ConvertSalesLeadConsumerRequest extends SalesLeadLifecycleRequest
{
    public function authorize(): bool
    {
        return $this->lead() !== null && ($this->user()?->can('convertToConsumer', $this->lead()) ?? false);
    }

    public function rules(): array
    {
        $lead = $this->lead();
        $normal = ! (bool) $lead?->project?->is_nup_eligible;

        return $this->operationRules() + [
            'project_id' => ['required', 'integer', Rule::in(array_filter([$lead?->project_id]))],
            'nik' => ['required', 'string', 'regex:/^\d{16}$/', function (string $attribute, mixed $value, Closure $fail): void {
                if (preg_match('/^(\d)\1{15}$/', (string) $value)) {
                    $fail('NIK tidak boleh berupa angka placeholder yang sama berulang kali.');
                }
            }],
            'id_kavling' => [$normal ? 'required' : 'nullable', 'string', 'max:100'],
            'nup' => ['nullable', 'string', 'max:100'],
            'tanggal_lahir' => ['nullable', 'date_format:Y-m-d'],
            'pekerjaan' => ['nullable', 'string', 'max:255'],
            'detail_pekerjaan' => ['nullable', 'string', 'max:255'],
            'alamat' => ['nullable', 'string', 'max:1000'],
            'kelurahan' => ['nullable', 'string', 'max:255'],
            'kecamatan' => ['nullable', 'string', 'max:255'],
            'kabupaten/kota' => ['nullable', 'string', 'max:255'],
            'nama_kondar' => ['nullable', 'string', 'max:255'],
            'no_hp_kondar' => ['nullable', 'string', 'max:50'],
            'status_cash' => ['nullable', Rule::in(['YA', 'TIDAK'])],
            'keterangan' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
