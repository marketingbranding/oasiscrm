<div class="sales-weekend-plan sales-submitted-plan" data-tour-target="work-summary">
    <div class="sales-submitted-meter">
        <strong>{{ $agendas->count() }} rencana akhir pekan</strong>
        <span>Tersimpan</span>
        <b class="sales-weekend-inline-stamp">AKHIR PEKAN</b>
    </div>
    <p class="sales-submitted-note">Akhir pekan bebas jam dan menit. Rencana langsung tersimpan tanpa proses kirim.</p>
    <div class="sales-submitted-count">{{ $receiptSummary['completed_count'] }} dari {{ $receiptSummary['agenda_count'] }} agenda selesai</div>
    <ol class="sales-submitted-list">
        @foreach($agendas as $agenda)
            @php
                $isRunning = false;
                if ($agenda->start_time && $agenda->end_time && $agenda->scheduled_date) {
                    $startsAt = \Carbon\CarbonImmutable::parse($agenda->scheduled_date->format('Y-m-d').' '.substr((string) $agenda->start_time, 0, 5), config('app.timezone'));
                    $endsAt = \Carbon\CarbonImmutable::parse($agenda->scheduled_date->format('Y-m-d').' '.substr((string) $agenda->end_time, 0, 5), config('app.timezone'));
                    $isRunning = $agenda->status !== 'done' && now(config('app.timezone'))->betweenIncluded($startsAt, $endsAt);
                }
                [$agendaStatusLabel, $agendaStatusVariant] = $isRunning ? ['Berjalan', 'info'] : ($agendaStatuses[$agenda->status] ?? [ucfirst(str_replace('_', ' ', $agenda->status)), 'neutral']);
            @endphp
            <li class="sales-submitted-row" data-tour-target="agenda-row">
                <a href="{{ route('sales-agendas.show', [$agenda, 'return_work_date' => $agendaDate->toDateString()]) }}" class="sales-submitted-row-main"><span class="sales-submitted-row-time">{{ $agenda->start_time && $agenda->end_time ? substr((string) $agenda->start_time, 0, 5).'–'.substr((string) $agenda->end_time, 0, 5) : 'Fleksibel' }}@if($agenda->start_time && $agenda->end_time)<small>{{ $agenda->duration_minutes }} mnt</small>@endif</span><span class="sales-submitted-row-copy"><strong>{{ $agenda->title }}</strong><em>{{ $agenda->sales_activity_category ?: 'Lainnya' }}</em></span></a>
                <x-crm.status-badge :variant="$agendaStatusVariant">{{ $agendaStatusLabel }}</x-crm.status-badge>
            </li>
        @endforeach
    </ol>
    <div class="sales-receipt-weekend-total"><span>TOTAL {{ $receiptSummary['normal_minutes'] }} MENIT</span><span>TERSIMPAN</span></div>
</div>

@if($project)
    <div id="agenda-input" class="sales-receipt-add-panel sales-reference-bottom-action" data-tour-target="add-plan-action"><button type="button" class="sales-receipt-add-trigger" @click="openAgendaSheet($event)" aria-haspopup="dialog" aria-controls="sales-agenda-create-sheet"><span>+ Tambah rencana akhir pekan</span></button></div>
@endif
