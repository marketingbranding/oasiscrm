<?php

namespace App\Http\Requests\Crm;

use App\Models\ConsumerApplication;
use App\Models\ConsumerLeadLinkDecision;
use App\Services\BranchMigrationStateService;
use App\Services\OrganizationScopeService;
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
            )
            && app(BranchMigrationStateService::class)->allowsOasisConsumerInput((int) $consumer->branch_id);
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
            'suggestions.*' => ['array:lead_id,score,rationale'],
            'suggestions.*.lead_id' => ['nullable', 'integer', 'exists:sales_leads,id'],
            'suggestions.*.score' => ['nullable', 'numeric', 'between:0,1'],
            'suggestions.*.rationale' => ['nullable', 'array', 'max:12'],
            'suggestions.*.rationale.*' => ['nullable', 'scalar', 'max:200'],
            'decision_source' => ['prohibited'],
        ];
    }
}
