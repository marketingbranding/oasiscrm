<?php

namespace App\Http\Requests\Crm;

use App\Models\SalesLeadSiteVisit;
use Illuminate\Validation\Rule;

class UpdateSalesLeadSiteVisitRequest extends SalesLeadLifecycleRequest
{
    public function authorize(): bool
    {
        $visit = $this->route('site_visit');

        return $this->lead() !== null
            && $visit instanceof SalesLeadSiteVisit
            && ($this->user()?->can('recordSiteVisit', $this->lead()) ?? false);
    }

    public function rules(): array
    {
        return [
            'completion' => ['required', Rule::in(['complete', 'isi_nanti'])],
            'tanggal' => ['nullable', 'required_if:completion,complete', 'date_format:Y-m-d'],
            'waktu' => ['nullable', 'required_if:completion,complete', Rule::in(['pagi', 'siang', 'sore', 'malam'])],
            'status' => ['nullable', 'required_if:completion,complete', Rule::in(['follow up', 'non ok', 'utj'])],
            'keterangan' => ['nullable', 'required_if:status,non ok', 'string', 'max:2000'],
            'operation_uuid' => ['prohibited'],
            'customer_name' => ['prohibited'],
            'sales_lead_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'sales_user_id' => ['prohibited'],
            'client_id' => ['prohibited'],
            'organization_id' => ['prohibited'],
        ];
    }
}
