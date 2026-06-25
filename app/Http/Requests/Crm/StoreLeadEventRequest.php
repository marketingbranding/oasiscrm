<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreLeadEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = Auth::user();
        return [
            'branch_id' => $user->canViewAllBranches() ? 'required|exists:branches,id' : 'nullable',
            'project_name' => 'required|string|max:255',
            'lead_source' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'total_budget' => 'nullable|numeric|min:0',
            'status' => 'required|in:berlangsung,selesai',
            'notes' => 'nullable|string',
        ];
    }
}
