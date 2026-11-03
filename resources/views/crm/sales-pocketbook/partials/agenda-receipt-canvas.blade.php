@php($isReceiptPreview = $isReceiptPreview ?? false)

@if($isReceiptPreview)
    <div x-show="receiptOpen" x-cloak x-ref="receiptPanel" id="sales-agenda-receipt-preview" data-agenda-receipt-preview class="sales-receipt-preview-backdrop" @click.self="closeReceipt()" @keydown="handleReceiptKeydown($event)" role="dialog" aria-modal="true" aria-labelledby="sales-receipt-preview-title">
@endif
        <div class="sales-receipt-canvas {{ $isReceiptPreview ? 'sales-receipt-canvas--preview' : '' }}" aria-label="Struk rencana agenda">
            @if($isReceiptPreview)
                <div class="sales-receipt-preview-header">
                    <span>Struk agenda</span>
                 <button type="button" x-ref="receiptClose" @click="closeReceipt()" aria-label="Simpan Draft">Simpan Draft</button>
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
                <div class="sales-receipt-paper-heading"><strong id="{{ $isReceiptPreview ? 'sales-receipt-preview-title' : 'agenda-receipt-title' }}">{{ $isToday ? 'RENCANA HARI INI' : ($isTomorrow ? 'RENCANA BESOK' : 'RENCANA '.$agendaDate->locale('id')->translatedFormat('d M Y')) }}</strong><span>{{ Auth::user()->name }} · {{ $agendaDate->locale('id')->translatedFormat('l, d M Y') }}</span>@if($isWeekend)<b class="sales-weekend-stamp">AKHIR PEKAN</b>@elseif(! $daySubmission)<b class="sales-draft-stamp">DRAFT</b>@endif</div>
                <ol class="sales-receipt-list">
                    @php($breakRendered = false)
                    @foreach($agendas as $agenda)
                        @if(! $isWeekend && ! $breakRendered && filled($agenda->start_time) && $toMinutes((string) $agenda->start_time) >= $toMinutes($schedule['break_end']))
                            @php($breakRendered = true)
                            <li class="sales-receipt-break-row">— ISTIRAHAT {{ $schedule['break_start'] }}–{{ $schedule['break_end'] }} —</li>
                        @endif
                         @php($breakAgenda = $isBreakAgenda($agenda))
                         @php($agendaLabels = [])
                         @if($agenda->is_outside_schedule)
                             @php($agendaLabels[] = 'Agenda tambahan · di luar target')
                         @endif
                         @if($breakAgenda)
                             @php($agendaLabels[] = 'Agenda jam istirahat')
                         @endif
                         <li class="sales-receipt-paper-row {{ $agenda->is_outside_schedule ? 'is-additional' : '' }} {{ $breakAgenda ? 'is-break' : '' }}" data-tour-target="agenda-row">
                             <span><b>{{ $agenda->start_time && $agenda->end_time ? substr((string) $agenda->start_time, 0, 5).'–'.substr((string) $agenda->end_time, 0, 5) : 'Fleksibel' }}</b>@if($agenda->start_time && $agenda->end_time)<small>{{ $agenda->duration_minutes }} mnt</small>@endif<br>{{ $agenda->title }}@if($agendaLabels)<small class="sales-agenda-break-label">{{ implode(' · ', $agendaLabels) }}</small>@endif</span>
                             @if($agenda->start_time && $agenda->end_time)<strong>{{ $agenda->duration_minutes }}m</strong>@endif
                             @if(! $daySubmission && $agenda->status === 'planned')
                                 <span class="sales-receipt-paper-row-actions" aria-label="Aksi {{ $agenda->title }}">
                                     <button type="button" class="sales-receipt-row-action sales-receipt-row-action--edit" data-agenda-edit data-agenda-id="{{ $agenda->id }}" data-agenda-update-url="{{ route('sales-agendas.plan.update', $agenda) }}" data-agenda-delete-url="{{ route('sales-agendas.plan.destroy', $agenda) }}" data-agenda-token="{{ app(\App\Services\OptimisticLockService::class)->token($agenda) }}" data-agenda-title="{{ $agenda->title }}" data-agenda-category="{{ $agenda->sales_activity_category }}" data-agenda-start="{{ $agenda->start_time ? substr((string) $agenda->start_time, 0, 5) : '' }}" data-agenda-end="{{ $agenda->end_time ? substr((string) $agenda->end_time, 0, 5) : '' }}" data-agenda-location="{{ $agenda->location }}" @click="openAgendaEdit($event)">Edit</button>
                                     <button type="button" class="sales-receipt-row-action sales-receipt-row-action--delete" data-agenda-delete data-agenda-id="{{ $agenda->id }}" data-agenda-delete-url="{{ route('sales-agendas.plan.destroy', $agenda) }}" data-agenda-token="{{ app(\App\Services\OptimisticLockService::class)->token($agenda) }}" data-agenda-title="{{ $agenda->title }}" @click="requestAgendaDelete($event)">Hapus</button>
                                 </span>
                             @endif
                              <a href="{{ route('sales-agendas.show', [$agenda, 'return_work_date' => $agendaDate->toDateString()]) }}" class="sr-only">Buka detail agenda {{ $agenda->title }}</a>
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
        @if(! $daySubmission)
            <div data-agenda-delete-modal="sales-agenda-delete-confirm">
                <x-crm.modal name="sales-agenda-delete-confirm" title="Hapus rencana agenda" description="Rencana yang dihapus tidak dapat dipulihkan." size="sm">
                    <form method="POST" action="#" x-bind:action="deleteAgendaUrl" @submit="deletePending = true">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="expected_updated_at" x-bind:value="deleteAgendaToken">
                        <p class="mb-4 text-sm">Hapus rencana <strong x-text="deleteAgendaTitle"></strong> dari struk?</p>
                        <div class="flex flex-wrap justify-end gap-2">
                            <button type="button" class="crm-button crm-button--secondary crm-button--md" :disabled="deletePending" @click="$dispatch('oasis:modal-close', { name: 'sales-agenda-delete-confirm' })">Batal</button>
                            <button type="submit" class="crm-button crm-button--danger crm-button--md" :disabled="deletePending" :aria-busy="deletePending" x-text="deletePending ? 'Menghapus...' : 'Hapus rencana'"></button>
                        </div>
                    </form>
                </x-crm.modal>
            </div>
        @endif
@if($isReceiptPreview)
    </div>
@endif
