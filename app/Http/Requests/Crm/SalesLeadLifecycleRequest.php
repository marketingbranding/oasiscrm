<?php

namespace App\Http\Requests\Crm;

use App\Models\SalesLead;
use Illuminate\Foundation\Http\FormRequest;

abstract class SalesLeadLifecycleRequest extends FormRequest
{
    protected function lead(): ?SalesLead
    {
        $lead = $this->route('sales_lead');

        return $lead instanceof SalesLead ? $lead : null;
    }

    protected function operationRules(): array
    {
        return [
            'operation_uuid' => ['nullable', 'uuid'],
            'branch_id' => ['prohibited'],
            'sales_user_id' => ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return [
            'operation_uuid' => 'identitas operasi',
            'project_id' => 'proyek',
            'id_kavling' => 'ID kavling',
            'nik' => 'NIK',
            'nik_koordinator' => 'NIK koordinator',
            'coordinator_user_id' => 'koordinator',
            'tanggal' => 'tanggal cek lokasi',
            'waktu' => 'waktu cek lokasi',
            'status' => 'hasil cek lokasi',
            'tanggal_slik' => 'tanggal SLIK',
            'hasil_slik' => 'hasil SLIK',
            'keterangan' => 'keterangan',
        ];
    }
}
