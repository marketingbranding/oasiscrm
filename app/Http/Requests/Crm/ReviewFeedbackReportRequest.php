<?php

namespace App\Http\Requests\Crm;

use App\Models\FeedbackReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewFeedbackReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(FeedbackReport::STATUSES)],
            'priority' => ['required', Rule::in(FeedbackReport::PRIORITIES)],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'admin_note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
