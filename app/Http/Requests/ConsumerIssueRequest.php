<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConsumerIssueRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('consumer_progress.manage')
            || $this->user()?->hasScopedPermission('consumer_progress', 'manage');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        if ($this->route()?->getName() === 'consumer-issues.resolve') {
            return ['resolution' => ['required', 'string', 'max:10000']];
        }

        return ['process_key' => ['required', 'in:data_konsumen,psjb,slik,pemberkasan,proses_bank,sp3k,ppjb,akad,bast,garansi,other'], 'category' => ['nullable', 'string', 'max:50'], 'description' => ['required', 'string', 'max:10000'], 'opened_at' => ['nullable', 'date'], 'pic_user_id' => ['nullable', 'integer', 'exists:users,id'], 'status' => ['nullable', 'in:open,in_progress,resolved']];
    }
}
