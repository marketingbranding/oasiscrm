<?php

namespace App\Http\Requests;

use App\Services\ConsumerMigrationReconciliationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConsumerMigrationReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('consumer_progress.manage') === true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(ConsumerMigrationReconciliationService::DECISIONS)],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id', 'required_without:new_customer_name'],
            'new_customer_name' => ['nullable', 'string', 'max:255', 'required_without:customer_id'],
            'target_kavling_id' => ['nullable', 'integer', 'exists:kavlings,id'],
        ];
    }
}
