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
            'task_detail' => 'nullable|string',
            'branch_id' => $user->canViewAllBranches() ? 'required|exists:branches,id' : 'nullable',
            'project_name' => 'nullable|string|max:255',
            'platform' => 'nullable|string|max:50',
            'start_date' => 'nullable|date',
            'deadline_date' => 'required|date',
            'priority' => 'required|in:low,medium,high,urgent',
            'pic_names' => 'nullable|array',
            'pic_names.*' => 'nullable|string|max:100',
            'status' => 'required|in:todo,in_progress,completed,lost_track',
            'notes' => 'nullable|string',
        ];
    }
}
