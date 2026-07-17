<?php

namespace App\Http\Requests\Crm;

use App\Models\ContentItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreContentItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = Auth::user();
        $type = $this->input('item_type', 'task');

        return [
            'item_type' => ['required', Rule::in(ContentItem::TYPES)],
            'visibility' => ['required', Rule::in(['personal', 'team'])],
            'title' => 'required|string|max:255',
            'task_detail' => 'nullable|string',
            'branch_id' => $user->canViewAllBranches() ? 'required|exists:branches,id' : 'nullable',
            'project_name' => 'nullable|string|max:255',
            'platform' => 'nullable|required_if:item_type,content|string|max:50',
            'agenda_type' => 'nullable|required_if:item_type,agenda|string|max:50',
            'location' => 'nullable|string|max:255',
            'start_date' => 'nullable|required_if:item_type,agenda|date',
            'start_time' => 'nullable|required_if:item_type,agenda|date_format:H:i',
            'deadline_date' => 'required|date|after_or_equal:start_date',
            'end_time' => 'nullable|date_format:H:i',
            'content_format' => 'nullable|required_if:item_type,content|string|max:50',
            'asset_url' => 'nullable|url|max:2000',
            'priority' => 'nullable|required_if:item_type,task|in:low,medium,high,urgent',
            'assigned_user_ids' => 'nullable|array',
            'assigned_user_ids.*' => 'integer|exists:users,id',
            'pic_names' => 'nullable|array',
            'pic_names.*' => 'nullable|string|max:100',
            'status' => ['required', Rule::in(ContentItem::STATUSES[$type] ?? ContentItem::STATUSES['task'])],
            'notes' => 'nullable|string',
        ];
    }
}
