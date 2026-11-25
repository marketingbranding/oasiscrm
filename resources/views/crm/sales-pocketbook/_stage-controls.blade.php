@php($stageV3 = \App\Support\FeatureFlag::enabledForBranch('lead_stages_v3', $lead->branch_id))
<div class="sales-lead-stage-controls" data-lead-id="{{ $lead->id }}" data-token="{{ app(\App\Services\OptimisticLockService::class)->token($lead) }}">
    @if($stageV3)
        @foreach(\App\Models\SalesLead::STAGE_V3_LABELS as $stage => $label)
            @if($stage !== 'gugur')
                @php($currentStage = $lead->resolveStageV3())
                <div class="sales-lead-stage-step">
                    <button type="button" class="stage-button {{ array_search($currentStage, \App\Models\SalesLead::STAGE_V3_ORDER, true) >= array_search($stage, \App\Models\SalesLead::STAGE_V3_ORDER, true) ? 'done' : '' }}" data-url="{{ route('sales-leads.stage.update', $lead) }}" data-stage="{{ $stage }}" data-label="{{ $label }}" data-current="{{ $currentStage === $stage ? $lead->lead_stage_changed_at?->format('Y-m-d H:i') : '' }}" data-stage-kind="value" @click="stage($event)" aria-label="{{ $currentStage === $stage ? 'Tahap saat ini '.$label : 'Catat tahap '.$label }}">
                        <span>{{ $currentStage === $stage ? 'Saat ini' : (array_search($currentStage, \App\Models\SalesLead::STAGE_V3_ORDER, true) > array_search($stage, \App\Models\SalesLead::STAGE_V3_ORDER, true) ? 'Selesai' : 'Catat') }}</span><strong>{{ $label }}</strong>
                    </button>
                </div>
            @endif
        @endforeach
    @else
        @foreach(\App\Models\SalesLead::STAGES as $stage => $label)
            <div class="sales-lead-stage-step">
                <button type="button" class="stage-button {{ $lead->{$stage} ? 'done' : '' }}" data-url="{{ route('sales-leads.stage.update', $lead) }}" data-stage="{{ $stage }}" data-label="{{ $label }}" data-current="{{ $lead->{$stage}?->format('Y-m-d H:i') }}" data-stage-kind="value" @click="stage($event)" aria-label="{{ $lead->{$stage} ? 'Ubah waktu tahap '.$label : 'Catat tahap '.$label }}">
                    <span>{{ $lead->{$stage} ? 'Tercatat' : 'Catat' }}</span><strong>{{ $label }}</strong>
                </button>
                @can('reverseStage', $lead)<button type="button" class="sales-lead-stage-reverse {{ $lead->{$stage} ? '' : 'hidden' }}" data-url="{{ route('sales-leads.stage.update', $lead) }}" data-stage="{{ $stage }}" data-label="{{ $label }}" data-current="{{ $lead->{$stage}?->format('Y-m-d H:i') }}" data-stage-kind="reverse" data-reverse="1" @click="stage($event)">Batalkan {{ $label }}</button>@endcan
            </div>
        @endforeach
    @endif
</div>
