<div class="flex flex-wrap gap-1" data-lead-id="{{ $lead->id }}" data-token="{{ app(\App\Services\OptimisticLockService::class)->token($lead) }}">
    @foreach(\App\Models\SalesLead::STAGES as $stage => $label)
        <button type="button" class="stage-button {{ $lead->{$stage} ? 'done' : '' }}" data-url="{{ route('sales-leads.stage.update', $lead) }}" data-stage="{{ $stage }}" data-label="{{ $label }}" data-current="{{ $lead->{$stage}?->format('Y-m-d H:i') }}" data-stage-kind="value" @click="stage($event)" title="{{ $lead->{$stage}?->format('d/m/Y H:i') }}">{{ $label }}</button>
        @if($monitoring)<button type="button" class="stage-button text-[#c0392b] {{ $lead->{$stage} ? '' : 'hidden' }}" data-url="{{ route('sales-leads.stage.update', $lead) }}" data-stage="{{ $stage }}" data-label="{{ $label }}" data-current="{{ $lead->{$stage}?->format('Y-m-d H:i') }}" data-stage-kind="reverse" data-reverse="1" @click="stage($event)" title="Batalkan tahap">x</button>@endif
    @endforeach
</div>
@if(auth()->user()->hasPermission('comments.view'))
<a href="{{ route('comments.thread', ['alias' => 'sales-lead', 'id' => $lead->id]) }}" class="mt-1 inline-block font-[Helvetica] text-[10px] font-bold text-[#0000ee] underline">Komentar ({{ $lead->comments_count }})</a>
@endif
