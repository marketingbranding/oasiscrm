<?php

namespace App\Http\Requests\Crm;

use Illuminate\Contracts\Validation\Validator;

class ConvertSalesLeadFreelanceRequest extends SalesLeadLifecycleRequest
{
    public function authorize(): bool
    {
        return $this->lead() !== null && ($this->user()?->can('convertToFreelance', $this->lead()) ?? false);
    }

    public function rules(): array
    {
        return $this->operationRules() + [
            'nik_koordinator' => ['required', 'string', 'max:100'],
            'coordinator_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'nik_sales' => ['prohibited'],
            'nama_sales' => ['prohibited'],
            'nama_koordinator' => ['prohibited'],
            'project_id' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $supervisor = $this->lead()?->sales?->supervisor;
            if (($supervisor === null || ! $supervisor->isAccountActive()) && blank($this->input('coordinator_user_id'))) {
                $validator->errors()->add('coordinator_user_id', 'Koordinator wajib dipilih saat Sales lead tidak memiliki atasan aktif.');
            }
        }];
    }
}
