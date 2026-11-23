<?php

namespace App\Http\Requests\Crm;

use App\Services\WorkspaceAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateDanaTalanganRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        $danaTalangan = $this->route('dana_talangan');

        return app(WorkspaceAccessService::class)->canEditBranch($user, $danaTalangan->branch_id);
    }

    public function rules(): array
    {
        $user = Auth::user();

        return [
            'tanggal' => 'required|date',
            'nama_konsumen' => 'required|string|max:255',
            'kav' => 'nullable|string|max:100',
            'id_transaksi' => 'nullable|string|max:80',
            'id_psjb' => 'nullable|string|max:150',
            'consumer_application_id' => 'nullable|integer|exists:consumer_applications,id',
            'kavling_id' => 'nullable|integer|exists:kavlings,id',
            'branch_id' => 'nullable|exists:branches,id',
            'expected_updated_at' => 'required|string|max:40',
            'project_name' => 'required|string|max:255',
            'pinjam_nama' => 'nullable|boolean',
            'pekerjaan' => 'nullable|string|max:255',
            'status_perkawinan' => 'nullable|string|max:100',
            'umur' => 'nullable|integer|min:0|max:150',
            'nama_marketing' => 'nullable|string|max:255',
            'tgl_komitmen' => 'nullable|date',
            'nominal' => 'nullable|numeric|min:0|max:9999999999999.99',
            'penyelesaian' => 'nullable|string',
            'konfirmasi_keuangan' => 'nullable|boolean',
            'status' => 'required|in:belum_ditentukan,sanggup,tidak_sanggup,lunas',
        ];
    }
}
