<?php

namespace App\Http\Requests\Crm;

use App\Models\ConsumerApplication;
use App\Models\ConsumerLeadLinkDecision;
use App\Services\OrganizationScopeService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConsumerLeadLinkDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $consumer = $this->route('consumerApplication');

        if (! $user || ! $consumer instanceof ConsumerApplication) {
            return false;
        }

        return $user->isAccountActive()
            && $user->is_active
            && $user->hasVerifiedEmail()
            && ! $user->must_change_password
            && $user->password_changed_at !== null
            && ($user->hasPermission('consumer_progress.manage') || $user->hasScopedPermission('consumer_progress', 'manage'))
            && app(OrganizationScopeService::class)->allowsProjectRecord(
                $user,
                'consumer_progress',
                'manage',
                (int) $consumer->branch_id,
                $consumer->project_id,
            );
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('suggestions', []) as $index => $suggestion) {
                $rationale = is_array($suggestion) ? ($suggestion['rationale'] ?? []) : [];
                foreach ((array) $rationale as $key => $value) {
                    if (! is_string($key) || mb_strlen($key) > 64) {
                        $validator->errors()->add("suggestions.{$index}.rationale", 'Kunci alasan snapshot saran maksimal 64 karakter.');
                    }
                    if ((! is_scalar($value) && $value !== null) || ($value !== null && mb_strlen((string) $value) > 200)) {
                        $validator->errors()->add("suggestions.{$index}.rationale", 'Nilai alasan snapshot saran maksimal 200 karakter.');
                    }
                }
            }
        });
    }

    public function rules(): array
    {
        $status = $this->input('status');

        return [
            'status' => ['required', 'string', Rule::in(ConsumerLeadLinkDecision::STATUSES)],
            'sales_lead_id' => [
                'nullable',
                'integer',
                'exists:sales_leads,id',
                Rule::requiredIf($status === ConsumerLeadLinkDecision::STATUS_LINKED),
                Rule::prohibitedIf(in_array($status, [ConsumerLeadLinkDecision::STATUS_WITHOUT_LEAD, ConsumerLeadLinkDecision::STATUS_LEGACY], true)),
            ],
            'reason' => [
                'nullable',
                'string',
                'max:2000',
                Rule::requiredIf(in_array($status, [ConsumerLeadLinkDecision::STATUS_WITHOUT_LEAD, ConsumerLeadLinkDecision::STATUS_LEGACY], true)),
            ],
            'legacy_anchor' => [
                'nullable',
                'string',
                Rule::in(['[SYS] ID Konsumen', 'ID Konsumen Awal']),
                Rule::requiredIf($status === ConsumerLeadLinkDecision::STATUS_LEGACY),
                Rule::prohibitedIf($status !== ConsumerLeadLinkDecision::STATUS_LEGACY),
            ],
            'suggestions' => ['nullable', 'array', 'max:20'],
            'suggestions.*' => ['array:lead_id,lead_name,sales_name,score,rationale'],
            'suggestions.*.lead_id' => ['nullable', 'integer', 'exists:sales_leads,id'],
            'suggestions.*.lead_name' => ['nullable', 'string', 'max:255'],
            'suggestions.*.sales_name' => ['nullable', 'string', 'max:255'],
            'suggestions.*.score' => ['nullable', 'numeric', 'between:0,1'],
            'suggestions.*.rationale' => ['nullable', 'array', 'max:12'],
            'suggestions.*.rationale.*' => ['nullable'],
            'decision_source' => ['prohibited'],
        ];
    }
}
