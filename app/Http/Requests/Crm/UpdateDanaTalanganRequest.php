<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateDanaTalanganRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        $danaTalangan = $this->route('dana_talangan');
        if (!$user->canViewAllBranches() && $danaTalangan->branch_id !== $user->branch_id) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        $user = Auth::user();
        return [
            'tanggal' => 'required|date',
            'nama_konsumen' => 'required|string|max:255',
            'kav' => 'nullable|string|max:100',
            'branch_id' => $user->canViewAllBranches() ? 'required|exists:branches,id' : 'exclude',
            'project_name' => 'nullable|string|max:255',
            'pinjam_nama' => 'nullable|boolean',
            'pekerjaan' => 'nullable|string|max:255',
            'status_perkawinan' => 'nullable|string|max:100',
            'umur' => 'nullable|integer|min:0|max:150',
            'nama_marketing' => 'nullable|string|max:255',
            'penyelesaian' => 'nullable|string',
            'konfirmasi_keuangan' => 'nullable|boolean',
            'status' => 'required|in:aktif,lunas',
        ];
    }
}
