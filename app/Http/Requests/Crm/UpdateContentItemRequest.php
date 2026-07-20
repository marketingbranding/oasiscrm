<?php

namespace App\Http\Requests\Crm;

use App\Models\ContentItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateContentItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        $contentItem = $this->route('content_calendar');
        if (! $user->canViewAllBranches() && $contentItem->branch_id !== $user->branch_id) {
            return false;
        }

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
            'platform' => ['nullable', Rule::requiredIf($type === 'content'), Rule::in(['Sosial Media', 'Website'])],
            'agenda_type' => 'nullable|required_if:item_type,agenda|string|max:50',
            'location' => 'nullable|string|max:255',
            'start_date' => ['nullable', Rule::requiredIf(in_array($type, ['agenda', 'content'], true)), 'date'],
            'start_time' => 'nullable|required_if:item_type,agenda|date_format:H:i',
            'deadline_date' => ['nullable', Rule::requiredIf($type !== 'content'), 'date', 'after_or_equal:start_date'],
            'end_time' => 'nullable|date_format:H:i',
            'content_format' => ['nullable', Rule::requiredIf($type === 'content'), Rule::in(['Video', 'Gambar', 'Video Karosel', 'Karosel', 'Artikel'])],
            'tujuan_konten' => ['nullable', Rule::requiredIf($type === 'content'), Rule::in(['Edukasi', 'Entertainment', 'Inspirasi'])],
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
