<?php

namespace App\Http\Requests\Crm;

use Illuminate\Validation\Rule;

class RecordSalesLeadSiteVisitRequest extends SalesLeadLifecycleRequest
{
    public function authorize(): bool
    {
        return $this->lead() !== null && ($this->user()?->can('recordSiteVisit', $this->lead()) ?? false);
    }

    public function rules(): array
    {
        return $this->operationRules() + [
            'completion' => ['required', Rule::in(['complete', 'isi_nanti'])],
            'tanggal' => ['nullable', 'required_if:completion,complete', 'date_format:Y-m-d'],
            'waktu' => ['nullable', 'required_if:completion,complete', Rule::in(['pagi', 'siang', 'sore', 'malam'])],
            'status' => ['nullable', 'required_if:completion,complete', Rule::in(['follow up', 'non ok', 'utj'])],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            'project_id' => ['prohibited'],
        ];
    }
}
