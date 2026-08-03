<?php

namespace App\Http\Requests\Crm;

use Illuminate\Validation\Rule;

class RejectSalesLeadSlikRequest extends SalesLeadLifecycleRequest
{
    public function authorize(): bool
    {
        return $this->lead() !== null && ($this->user()?->can('markSlikRejected', $this->lead()) ?? false);
    }

    public function rules(): array
    {
        return $this->operationRules() + [
            'hasil_slik' => ['required', Rule::in(['KOL 1', 'KOL 2', 'KOL 3', 'KOL 4', 'KOL 5', 'NO BIC'])],
            'keterangan' => ['required', 'string', 'max:2000'],
            'project_id' => ['prohibited'],
            'nik' => ['prohibited'],
            'id_kavling' => ['prohibited'],
        ];
    }
}
