<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateLeadDailyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        $leadDaily = $this->route('lead_daily');
        if (!$user->canViewAllBranches() && $leadDaily->branch_id !== $user->branch_id) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'required|date',
            'leads_count' => 'required|integer|min:0',
        ];
    }
}
