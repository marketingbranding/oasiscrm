<?php

namespace App\Http\Requests\Crm;

class SubmitSalesLeadSlikRequest extends SalesLeadLifecycleRequest
{
    public function authorize(): bool
    {
        return $this->lead() !== null && ($this->user()?->can('submitToSlik', $this->lead()) ?? false);
    }

    public function rules(): array
    {
        return $this->operationRules() + [
            'tanggal_slik' => ['required', 'date_format:Y-m-d'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            'project_id' => ['prohibited'],
            'nik' => ['prohibited'],
            'id_kavling' => ['prohibited'],
        ];
    }
}
