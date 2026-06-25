<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateContentItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        $contentItem = $this->route('content_calendar');
        if (!$user->canViewAllBranches() && $contentItem->branch_id !== $user->branch_id) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        $user = Auth::user();
        return [
            'title' => 'required|string|max:255',
            'branch_id' => $user->canViewAllBranches() ? 'required|exists:branches,id' : 'nullable',
            'project_name' => 'nullable|string|max:255',
            'platform' => 'nullable|string|max:50',
            'scheduled_date' => 'required|date',
            'status' => 'required|in:draft,review,approved,posted',
            'notes' => 'nullable|string',
        ];
    }
}
