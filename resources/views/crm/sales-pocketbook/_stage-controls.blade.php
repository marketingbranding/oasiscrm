<div class="sales-lead-stage-controls" data-lead-id="{{ $lead->id }}" data-token="{{ app(\App\Services\OptimisticLockService::class)->token($lead) }}">
    @foreach(\App\Models\SalesLead::STAGES as $stage => $label)
        <div class="sales-lead-stage-step">
            <button type="button" class="stage-button {{ $lead->{$stage} ? 'done' : '' }}" data-url="{{ route('sales-leads.stage.update', $lead) }}" data-stage="{{ $stage }}" data-label="{{ $label }}" data-current="{{ $lead->{$stage}?->format('Y-m-d H:i') }}" data-stage-kind="value" @click="stage($event)" aria-label="{{ $lead->{$stage} ? 'Ubah waktu tahap '.$label : 'Catat tahap '.$label }}">
                <span>{{ $lead->{$stage} ? 'Tercatat' : 'Catat' }}</span><strong>{{ $label }}</strong>
            </button>
            @if($monitoring)<button type="button" class="sales-lead-stage-reverse {{ $lead->{$stage} ? '' : 'hidden' }}" data-url="{{ route('sales-leads.stage.update', $lead) }}" data-stage="{{ $stage }}" data-label="{{ $label }}" data-current="{{ $lead->{$stage}?->format('Y-m-d H:i') }}" data-stage-kind="reverse" data-reverse="1" @click="stage($event)">Batalkan {{ $label }}</button>@endif
        </div>
    @endforeach
</div>
