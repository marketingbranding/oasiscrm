<?php

namespace App\Http\Requests\Crm;

use App\Models\SalesLead;
use App\Support\FeatureFlag;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateSalesLeadStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lead = $this->route('sales_lead');
        if (! $lead instanceof SalesLead) {
            return false;
        }

        if (FeatureFlag::enabled('lead_stages_v3')) {
            return $this->user()?->can('updateStageV3', $lead) ?? false;
        }

        return $this->user()?->can($this->input('action') === 'reverse' ? 'reverseStage' : 'updateStage', $lead) ?? false;
    }

    public function rules(): array
    {
        if (FeatureFlag::enabled('lead_stages_v3')) {
            return [
                'stage' => ['required', Rule::in(SalesLead::STAGE_V3_ORDER)],
                'action' => ['required', Rule::in(['set', 'reverse'])],
                'reason' => ['nullable', 'string', 'max:500', 'required_if:action,reverse'],
                'reversal_confirmed' => ['nullable', 'accepted_if:action,reverse'],
                'expected_updated_at' => ['required', 'string', 'max:40'],
                'operation_uuid' => ['nullable', 'uuid'],
            ];
        }

        return [
            'stage' => ['required', Rule::in(SalesLead::STAGE_ORDER)],
            'action' => ['required', Rule::in(['set', 'reverse'])],
            'timestamp' => ['nullable', 'required_if:action,set', 'date'],
            'reversal_confirmed' => ['nullable', 'accepted_if:action,reverse'],
            'expected_updated_at' => ['required', 'string', 'max:40'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->input('action') !== 'set' || FeatureFlag::enabled('lead_stages_v3')) {
                return;
            }

            /** @var SalesLead $lead */
            $lead = $this->route('sales_lead');
            $stage = (string) $this->input('stage');
            $timestamp = Carbon::parse($this->input('timestamp'));
            if ($timestamp->lt($lead->lead_date->startOfDay())) {
                $validator->errors()->add('timestamp', 'Waktu progres tidak boleh sebelum tanggal lead.');
            }

            $position = array_search($stage, SalesLead::STAGE_ORDER, true);
            foreach (SalesLead::STAGE_ORDER as $index => $otherStage) {
                $other = $lead->{$otherStage};
                if ($other && $index < $position && $timestamp->lt($other)) {
                    $validator->errors()->add('timestamp', 'Waktu progres harus berurutan setelah tahap sebelumnya.');
                    break;
                }
                if ($other && $index > $position && $timestamp->gt($other)) {
                    $validator->errors()->add('timestamp', 'Waktu progres harus berurutan sebelum tahap berikutnya.');
                    break;
                }
            }
        }];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422));
    }
}
