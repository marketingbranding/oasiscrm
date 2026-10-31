@php($isReceiptPreview = $isReceiptPreview ?? false)

@if($isReceiptPreview)
    <div x-show="receiptOpen" x-cloak x-ref="receiptPanel" id="sales-agenda-receipt-preview" data-agenda-receipt-preview class="sales-receipt-preview-backdrop" @click.self="closeReceipt()" @keydown="handleReceiptKeydown($event)" role="dialog" aria-modal="true" aria-labelledby="sales-receipt-preview-title">
@endif
        <div class="sales-receipt-canvas {{ $isReceiptPreview ? 'sales-receipt-canvas--preview' : '' }}" aria-label="Struk rencana agenda">
            @if($isReceiptPreview)
                <div class="sales-receipt-preview-header">
                    <span>Struk agenda</span>
                    <button type="button" x-ref="receiptClose" @click="closeReceipt()" aria-label="Tutup struk">&times;</button>
                </div>
            @endif
            @if($isWeekend)
                <div class="sales-receipt-weekend-header" data-tour-target="work-summary"><strong>{{ $agendaDate->locale('id')->translatedFormat('l') }} · hari bebas</strong><b>AKHIR PEKAN</b></div>
            @else
                <div class="sales-receipt-light-meter" data-tour-target="work-summary">
                    <div><strong>{{ $receiptSummary['normal_minutes'] }} / {{ $receiptSummary['target_minutes'] }} menit</strong><span>{{ $receiptSummary['remaining_minutes'] > 0 ? 'Isi '.$receiptSummary['remaining_minutes'].' menit lagi' : 'Target terpenuhi' }}</span></div>
                    <div class="sales-receipt-light-meter-track" role="progressbar" aria-label="Progress menit agenda" aria-valuemin="0" aria-valuemax="{{ $receiptSummary['target_minutes'] }}" aria-valuenow="{{ $receiptSummary['normal_minutes'] }}"><span style="width: {{ min(100, ($receiptSummary['normal_minutes'] / max(1, $receiptSummary['target_minutes'])) * 100) }}%"></span></div>
                </div>
            @endif

            <div class="sales-receipt-paper rc {{ $isWeekend ? 'is-weekend' : '' }}">
                <div class="sales-receipt-paper-heading"><strong id="{{ $isReceiptPreview ? 'sales-receipt-preview-title' : 'agenda-receipt-title' }}">RENCANA HARI INI</strong><span>{{ Auth::user()->name }} · {{ $agendaDate->locale('id')->translatedFormat('l, d M Y') }}</span>@if($isWeekend)<b class="sales-weekend-stamp">AKHIR PEKAN</b>@endif</div>
                <ol class="sales-receipt-list">
                    @php($breakRendered = false)
                    @foreach($agendas as $agenda)
                        @if(! $isWeekend && ! $breakRendered && filled($agenda->start_time) && $toMinutes((string) $agenda->start_time) >= $toMinutes($schedule['break_end']))
                            @php($breakRendered = true)
                            <li class="sales-receipt-break-row">— ISTIRAHAT {{ $schedule['break_start'] }}–{{ $schedule['break_end'] }} —</li>
                        @endif
                         @php($breakAgenda = ! $agenda->is_outside_schedule && $isBreakAgenda($agenda))
                         <li class="sales-receipt-paper-row {{ $agenda->is_outside_schedule ? 'is-additional' : '' }} {{ $breakAgenda ? 'is-break' : '' }}" data-tour-target="agenda-row">
                             <span><b>{{ $agenda->start_time && $agenda->end_time ? substr((string) $agenda->start_time, 0, 5).'–'.substr((string) $agenda->end_time, 0, 5) : 'Fleksibel' }}</b>@if($agenda->start_time && $agenda->end_time)<small>{{ $agenda->duration_minutes }} mnt</small>@endif<br>{{ $agenda->title }}@if($breakAgenda)<small class="sales-agenda-break-label">Agenda jam istirahat</small>@endif</span>
                            @if($agenda->start_time && $agenda->end_time)<strong>{{ $agenda->duration_minutes }}m</strong>@endif
                            <a href="{{ route('sales-agendas.show', $agenda) }}" class="sr-only">Buka detail agenda {{ $agenda->title }}</a>
                        </li>
                    @endforeach
                    @if(! $agendas->isNotEmpty() && ! $isWeekend)
                        <li class="sales-receipt-empty-row">Belum ada agenda · tambahkan rencana kerja</li>
                    @endif
                    @if(! $isWeekend && $freeMinutes > 0)
                        <li class="sales-receipt-open-slot">{{ $formatMinutes($lastEnd) }}–{{ $schedule['end_time'] }} · kosong {{ $freeMinutes }} menit</li>
                    @endif
                </ol>
                @if($isWeekend)
                    <div class="sales-receipt-weekend-total"><span>TOTAL {{ $receiptSummary['normal_minutes'] }} MENIT</span><span>{{ $daySubmission ? 'TERKIRIM' : 'TANPA TARGET' }}</span></div>
                @else
                    <div class="sales-receipt-weekday-total"><span>TARGET HARIAN</span><strong>{{ $receiptSummary['target_minutes'] }} MENIT</strong></div>
                @endif
            </div>

            <div class="sales-receipt-canvas-actions">
                <div class="sales-reference-bottom-action is-receipt-action" data-tour-target="add-plan-action">
                    <button type="button" class="sales-receipt-add-trigger" data-receipt-preview-add @click="openAgendaSheet($event)" aria-haspopup="dialog" aria-controls="sales-agenda-create-sheet"><span>+ Tambah agenda ke struk</span></button>
                </div>
                @if(! $isReceiptPreview && (($isWeekend && $receiptSummary['agenda_count'] > 0) || (!$isWeekend && $receiptSummary['normal_minutes'] === $receiptSummary['target_minutes'])))
                    <form method="POST" action="{{ route('sales-agendas.submit') }}" class="sales-receipt-submit-form" data-receipt-submit>
                        @csrf
                        <input type="hidden" name="scheduled_date" value="{{ $agendaDate->toDateString() }}">
                         <div class="sales-receipt-submit-help">{{ $isWeekend ? 'Akhir pekan bebas jam dan menit. Koordinator melihat tanda Akhir pekan.' : 'Total tepat '.$receiptSummary['target_minutes'].' menit. Struk akan disobek dan terkirim ke atas.' }}</div>
                        <button type="submit" class="crm-button crm-button--primary crm-button--lg" data-accent="sales">Sobek &amp; kirim ke Koordinator</button>
                    </form>
                @elseif(! $isReceiptPreview && ! $daySubmission)
                    <div class="sales-receipt-submit-disabled">Isi {{ $receiptSummary['remaining_minutes'] }} menit lagi untuk mengirim</div>
                @endif
            </div>
        </div>
@if($isReceiptPreview)
    </div>
@endif
