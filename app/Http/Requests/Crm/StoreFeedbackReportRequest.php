<?php

namespace App\Http\Requests\Crm;

use App\Models\FeedbackReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeedbackReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->title),
            'description' => trim((string) $this->description),
            'module' => trim((string) $this->module),
            'activity' => trim((string) $this->activity),
            'expected_result' => trim((string) $this->expected_result),
            'actual_result' => trim((string) $this->actual_result),
            'suggestion' => trim((string) $this->suggestion),
            'impact' => trim((string) $this->impact),
            'additional_notes' => trim((string) $this->additional_notes),
        ]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(FeedbackReport::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'module' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:5000'],
            'branch_id' => ['required', 'integer'],
            'activity' => [Rule::requiredIf($this->input('type') === 'bug'), 'nullable', 'string', 'max:5000'],
            'actual_result' => [Rule::requiredIf($this->input('type') === 'bug'), 'nullable', 'string', 'max:5000'],
            'expected_result' => [Rule::requiredIf($this->input('type') === 'bug'), 'nullable', 'string', 'max:5000'],
            'reproduction_frequency' => ['nullable', Rule::in(['selalu', 'sering', 'kadang', 'baru_sekali', 'tidak_tahu'])],
            'suggestion' => [Rule::requiredIf(in_array($this->input('type'), ['masukan', 'permintaan_fitur'], true)), 'nullable', 'string', 'max:5000'],
            'impact' => [Rule::requiredIf($this->input('type') === 'masukan'), 'nullable', 'string', 'max:3000'],
            'target_users' => [Rule::requiredIf($this->input('type') === 'permintaan_fitur'), 'nullable', 'string', 'max:255'],
            'need_level' => ['nullable', Rule::in(['rendah', 'sedang', 'tinggi', 'mendesak'])],
            'additional_notes' => ['nullable', 'string', 'max:3000'],
            'page_url' => ['nullable', 'url', 'max:2000'],
            'route_name' => ['nullable', 'string', 'max:255'],
            'user_agent_summary' => ['nullable', 'string', 'max:255'],
            'screen_size' => ['nullable', 'regex:/^\d{2,5}x\d{2,5}$/'],
            'screenshot' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
        ];
    }
}
