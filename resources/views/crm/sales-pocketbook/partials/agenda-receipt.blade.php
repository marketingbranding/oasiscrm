@php
    $today = \Carbon\CarbonImmutable::today(config('app.timezone'));
    $isWeekend = $receiptSummary['is_weekend'];
    $isReceiptMode = ! $daySubmission && $agendas->isNotEmpty();
    $isToday = $agendaDate->isSameDay($today);
    $isYesterday = $agendaDate->isSameDay($today->subDay());
    $isPlan = $agendaDate->greaterThan($today);
    $schedule = $receiptSummary['schedule'];
    $agendaStatuses = [
        'planned' => ['Menunggu', 'pending'],
        'confirmed' => ['Dikonfirmasi', 'success'],
        'done' => ['Selesai', 'success'],
        'cancelled' => ['Dibatalkan', 'inactive'],
        'rescheduled' => ['Ditunda ke besok', 'warning'],
    ];
    $toMinutes = static function (?string $time): int {
        [$hours, $minutes] = array_map('intval', explode(':', substr((string) $time, 0, 5)));

        return ($hours * 60) + $minutes;
    };
    $lastEnd = $agendas->filter(fn ($agenda): bool => filled($agenda->end_time))->max(fn ($agenda): int => $toMinutes((string) $agenda->end_time));
    $formatMinutes = static function (?int $minutes): string {
        $minutes ??= 0;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    };
    $isBreakAgenda = static function ($agenda) use ($isWeekend, $schedule, $toMinutes): bool {
        if ($isWeekend || blank($agenda->start_time) || blank($agenda->end_time)) {
            return false;
        }

        return $toMinutes((string) $agenda->start_time) < $toMinutes((string) $schedule['break_end'])
            && $toMinutes((string) $agenda->end_time) > $toMinutes((string) $schedule['break_start']);
    };
    $freeMinutes = ! $isWeekend && $lastEnd ? max(0, $toMinutes($schedule['end_time']) - $lastEnd) : 0;
    $agendaSlotData = $agendas->map(fn ($agenda): array => [
        'startTime' => $agenda->start_time ? substr((string) $agenda->start_time, 0, 5) : null,
        'endTime' => $agenda->end_time ? substr((string) $agenda->end_time, 0, 5) : null,
        'durationMinutes' => $agenda->duration_minutes,
        'status' => $agenda->status,
        'isOutsideSchedule' => (bool) $agenda->is_outside_schedule,
    ])->values()->all();
    $defaultDuration = (int) old('duration_minutes', 30);
    $hasOldStart = filled(old('start_time'));
    $defaultStartMinutes = $hasOldStart
        ? $toMinutes((string) old('start_time'))
        : ($isWeekend ? null : ($lastEnd ?: $toMinutes($schedule['start_time'])));
    if (! $hasOldStart && ! $isWeekend && $defaultStartMinutes !== null) {
        $breakStart = $toMinutes((string) $schedule['break_start']);
        $breakEnd = $toMinutes((string) $schedule['break_end']);
        if (($defaultStartMinutes >= $breakStart && $defaultStartMinutes < $breakEnd)
            || ($defaultStartMinutes < $breakStart && $defaultStartMinutes + $defaultDuration > $breakStart)) {
            $defaultStartMinutes = $breakEnd;
        }
    }
    $defaultEndMinutes = $defaultStartMinutes !== null ? $defaultStartMinutes + $defaultDuration : null;
    $defaultStart = $defaultStartMinutes !== null ? $formatMinutes($defaultStartMinutes) : '';
    $defaultEnd = $defaultEndMinutes !== null ? $formatMinutes($defaultEndMinutes) : '';
    $defaultRemaining = ! $isWeekend && $defaultEndMinutes !== null
        ? max(0, $receiptSummary['target_minutes'] - $receiptSummary['normal_minutes'] - $defaultDuration)
        : null;
@endphp

<section id="agenda-list" class="sales-receipt-workspace {{ $isReceiptMode ? 'is-receipt-mode' : '' }} {{ $daySubmission ? 'is-submitted-mode' : '' }}" aria-labelledby="agenda-receipt-title">
    @if($isReceiptMode)
        @include('crm.sales-pocketbook.partials.agenda-receipt-canvas', ['isReceiptPreview' => false])
    @else
        <div class="sales-receipt-contexts" aria-label="Pilihan hari agenda">
            <a href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $today->subDay()->toDateString()]) }}" class="{{ $isYesterday ? 'is-active' : '' }}"><span>Kemarin</span><strong>{{ $today->subDay()->format('d') }}</strong></a>
            <a href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $today->toDateString()]) }}" class="{{ $isToday ? 'is-active' : '' }}"><span>Hari ini</span><strong>{{ $today->format('d') }}</strong></a>
            <a href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $today->addDay()->toDateString()]) }}" class="{{ $isPlan ? 'is-active' : '' }}"><span>Rencana</span></a>
        </div>

        @if($daySubmission)
            <div class="sales-submitted-plan" data-tour-target="work-summary">
                <div class="sales-submitted-meter"><strong>{{ $receiptSummary['target_minutes'] ? $receiptSummary['target_minutes'].' / '.$receiptSummary['target_minutes'].' menit' : 'Akhir pekan · '.$receiptSummary['normal_minutes'].' menit' }}</strong><span>Terkirim ke Koordinator</span>@if($isWeekend)<b class="sales-weekend-inline-stamp">AKHIR PEKAN</b>@endif</div>
                <p class="sales-submitted-note">{{ $isWeekend ? 'Akhir pekan bebas jam dan menit.' : 'Waktu, judul, kategori, dan lokasi agenda terjadwal tidak dapat diubah.' }}</p>
                @if($receiptSummary['target_minutes'])<div class="sales-submitted-meter-track"><span style="width:100%"></span></div>@endif
                <div class="sales-submitted-count">{{ $receiptSummary['completed_count'] }} dari {{ $receiptSummary['agenda_count'] }} agenda selesai</div>
                <ol class="sales-submitted-list">
                    @php $breakRendered = false; @endphp
                    @foreach($agendas as $agenda)
                        @if(! $isWeekend && ! $breakRendered && filled($agenda->start_time) && $toMinutes((string) $agenda->start_time) >= $toMinutes($schedule['break_end']))
                            @php $breakRendered = true; @endphp
                            <li class="sales-agenda-break-row">Istirahat {{ $schedule['break_start'] }}–{{ $schedule['break_end'] }}</li>
                        @endif
                        @php
                            $isRunning = false;
                            if ($agenda->start_time && $agenda->end_time && $agenda->scheduled_date) {
                                $startsAt = \Carbon\CarbonImmutable::parse($agenda->scheduled_date->format('Y-m-d').' '.substr((string) $agenda->start_time, 0, 5), config('app.timezone'));
                                $endsAt = \Carbon\CarbonImmutable::parse($agenda->scheduled_date->format('Y-m-d').' '.substr((string) $agenda->end_time, 0, 5), config('app.timezone'));
                                $isRunning = $agenda->status !== 'done' && now(config('app.timezone'))->betweenIncluded($startsAt, $endsAt);
                            }
                             [$agendaStatusLabel, $agendaStatusVariant] = $isRunning ? ['Berjalan', 'info'] : ($agendaStatuses[$agenda->status] ?? [ucfirst(str_replace('_', ' ', $agenda->status)), 'neutral']);
                             $breakAgenda = ! $agenda->is_outside_schedule && $isBreakAgenda($agenda);
                         @endphp
                         <li class="sales-submitted-row {{ $breakAgenda ? 'is-break' : '' }}" data-tour-target="agenda-row">
                             <a href="{{ route('sales-agendas.show', $agenda) }}" class="sales-submitted-row-main"><span class="sales-submitted-row-time">{{ $agenda->start_time && $agenda->end_time ? substr((string) $agenda->start_time, 0, 5).'–'.substr((string) $agenda->end_time, 0, 5) : 'Fleksibel' }}@if($agenda->start_time && $agenda->end_time)<small>{{ $agenda->duration_minutes }} mnt</small>@endif</span><span class="sales-submitted-row-copy"><strong>{{ $agenda->title }}</strong><em>{{ $breakAgenda ? 'Agenda jam istirahat' : ($agenda->is_outside_schedule ? 'Agenda tambahan · di luar target' : ($agenda->sales_activity_category ?: 'Lainnya')) }}</em></span></a>
                            <x-crm.status-badge :variant="$agendaStatusVariant">{{ $agendaStatusLabel }}</x-crm.status-badge>
                        </li>
                    @endforeach
                </ol>
                @if($isWeekend)<div class="sales-receipt-weekend-total"><span>TOTAL {{ $receiptSummary['normal_minutes'] }} MENIT</span><span>TANPA TARGET</span></div>@endif
            </div>
            @if($project)
                <div id="agenda-input" class="sales-receipt-add-panel sales-reference-bottom-action" data-tour-target="add-plan-action"><button type="button" class="sales-receipt-add-trigger" @click="openAgendaPreview($event)" aria-haspopup="dialog" aria-controls="sales-agenda-receipt-preview"><span>+ Tambah rencana {{ $isWeekend ? 'akhir pekan' : 'hari ini' }}</span></button></div>
            @endif
        @else
            <section class="sales-reference-summary {{ $isWeekend ? 'is-weekend' : '' }}" aria-label="Ringkasan {{ $isWeekend ? 'rencana akhir pekan' : 'jam kerja harian' }}" data-tour-target="work-summary">
                @if($isWeekend)
                    <div class="sales-reference-summary-heading"><span>Akhir pekan · bebas</span><strong>Tanpa target</strong><small>Jam dan durasi bebas · buat rencana bila ada usaha ekstra</small><b class="sales-weekend-inline-stamp">AKHIR PEKAN</b></div>
                @else
                     <div class="sales-reference-summary-heading"><span>Jam kerja {{ $agendaDate->locale('id')->translatedFormat('l') }}</span><strong>{{ $schedule['start_time'] }}–{{ $schedule['end_time'] }}</strong><small>Istirahat {{ $schedule['break_start'] }}–{{ $schedule['break_end'] }}</small></div>
                    <div class="sales-reference-progress"><div><span>{{ $receiptSummary['normal_minutes'] }} / {{ $receiptSummary['target_minutes'] }} menit</span><strong>target hari ini</strong></div><div class="sales-reference-progress-track" role="progressbar" aria-label="Progress rencana hari ini" aria-valuemin="0" aria-valuemax="{{ $receiptSummary['target_minutes'] }}" aria-valuenow="{{ $receiptSummary['normal_minutes'] }}"><span style="width: {{ min(100, ($receiptSummary['normal_minutes'] / max(1, $receiptSummary['target_minutes'])) * 100) }}%"></span></div></div>
                @endif
            </section>
             <div class="sales-reference-empty" data-tour-target="agenda-row" role="status"><div class="sales-reference-empty-mark" aria-hidden="true">＋</div><strong>{{ $isWeekend ? 'Belum ada rencana akhir pekan' : 'Belum ada rencana hari ini' }}</strong><span>{{ $isWeekend ? 'Tambahkan aktivitas bila ada rencana usaha ekstra.' : 'Tulis rencana kerja 480 menit di struk, lalu sobek dan kirim ke Koordinator.' }}</span><span class="sr-only">Belum ada agenda</span></div>
            @if(! $isWeekend && $incompletePreviousAgendas->isNotEmpty() && $isToday)
                <div class="sales-receipt-warning" role="status"><strong>{{ $incompletePreviousAgendas->count() }} rencana kemarin belum lengkap</strong><span>{{ $incompletePreviousAgendas->count() }} agenda belum ada bukti foto · lengkapi sekarang</span></div>
            @endif
            @if($project)
                <div id="agenda-input" class="sales-receipt-add-panel sales-reference-bottom-action" data-tour-target="add-plan-action"><button type="button" class="sales-receipt-add-trigger" @click="openAgendaPreview($event)" aria-haspopup="dialog" aria-controls="sales-agenda-receipt-preview"><span>+ Tambah rencana {{ $isWeekend ? 'akhir pekan' : 'hari ini' }}</span></button></div>
            @endif
        @endif
    @endif

    @if(! $isReceiptMode && $project)
        @include('crm.sales-pocketbook.partials.agenda-receipt-canvas', ['isReceiptPreview' => true])
    @endif

    @if($project)
        <div x-show="agendaOpen" x-cloak class="sales-sheet-backdrop" @click.self="closeSheet()" @keydown="handleSheetKeydown($event)" role="dialog" aria-modal="true" aria-labelledby="sales-agenda-create-title" aria-describedby="sales-agenda-create-description">
            <div id="sales-agenda-create-sheet" x-ref="agendaPanel" class="sales-bottom-sheet">
                <div class="sales-bottom-sheet-handle" aria-hidden="true"></div>
                <div class="sales-bottom-sheet-header"><div><span class="sales-bottom-sheet-eyebrow">Agenda {{ $agendaDate->locale('id')->translatedFormat('d F Y') }}</span><h2 id="sales-agenda-create-title">Tambah agenda ke struk</h2></div><button type="button" x-ref="sheetClose" @click="closeSheet()" class="sales-bottom-sheet-close" aria-label="Tutup form agenda">&times;</button></div>
                <p id="sales-agenda-create-description" class="sales-bottom-sheet-description">Pilih kategori, isi judul, lalu atur waktu dan durasi kegiatan.</p>
                <form method="POST" action="{{ route('sales-agendas.store') }}" class="sales-receipt-form" data-agenda-slot-form data-agenda-date="{{ $agendaDate->toDateString() }}" data-agendas='@json($agendaSlotData)' data-schedule='@json($schedule)'>
                    @csrf
                    <input type="hidden" name="scheduled_date" value="{{ $agendaDate->toDateString() }}">
                    <x-crm.field label="Kategori" for="sales-agenda-category" required><select id="sales-agenda-category" class="sales-input sales-chip-select" name="sales_activity_category" required data-autofocus><option value="">Pilih kategori</option>@foreach(\App\Models\ContentItem::SALES_ACTIVITY_CATEGORIES as $category)<option value="{{ $category }}" @selected(old('sales_activity_category') === $category)>{{ $category }}</option>@endforeach</select><div class="sales-category-chips" aria-label="Kategori agenda">@foreach(\App\Models\ContentItem::SALES_ACTIVITY_CATEGORIES as $category)<button type="button" class="sales-category-chip" data-category-option="{{ $category }}">{{ $category }}</button>@endforeach</div></x-crm.field>
                    <x-crm.field label="Judul agenda" for="sales-agenda-title" required><input id="sales-agenda-title" class="sales-input" name="title" value="{{ old('title') }}" required></x-crm.field>
                    <x-crm.field label="Mulai" for="sales-agenda-start-time" required><input id="sales-agenda-start-time" class="sales-input sales-agenda-start-input" name="start_time" type="time" value="{{ old('start_time', $defaultStart) }}" required @change="syncSlot()" @input="syncSlot()" data-slot-start><span class="sales-field-error" data-slot-error role="alert" aria-live="polite"></span></x-crm.field>
                    <x-crm.field label="Durasi" for="sales-agenda-duration" required><select id="sales-agenda-duration" class="sr-only" name="duration_minutes" required @change="syncSlot()"><option value="">Pilih durasi</option>@foreach([30, 60, 90, 120] as $duration)<option value="{{ $duration }}" @selected($defaultDuration === $duration)>{{ $duration }} menit</option>@endforeach</select><div class="sales-duration-chips" aria-label="Durasi agenda">@foreach([30, 60, 90, 120] as $duration)<button type="button" class="sales-duration-chip" data-duration-option="{{ $duration }}">{{ $duration }}</button>@endforeach<button type="button" class="sales-duration-chip" data-duration-option="custom">Custom</button><input type="number" min="5" step="5" class="sales-input sales-custom-duration" name="custom_duration_minutes" placeholder="menit" aria-label="Durasi custom dalam menit" @input="syncCustomDuration()"></div><span class="sales-field-preview" data-end-preview aria-live="polite">{{ $defaultEnd ? 'Selesai '.$defaultEnd.($defaultRemaining !== null ? ' · sisa '.$defaultRemaining.' menit' : '') : 'Pilih jam mulai untuk menghitung waktu selesai.' }}</span></x-crm.field>
                    <input type="hidden" name="end_time" value="{{ old('end_time', $defaultEnd) }}" data-slot-end>
                    <x-crm.field label="Lokasi" for="sales-agenda-location"><input id="sales-agenda-location" class="sales-input" name="location" value="{{ old('location') }}"></x-crm.field>
                    <x-crm.field label="Hasil awal" for="sales-agenda-result" hint="Opsional; isi setelah kegiatan bila belum tersedia." class="md:col-span-2"><textarea id="sales-agenda-result" class="sales-input" name="activity_result" rows="2">{{ old('activity_result') }}</textarea></x-crm.field>
                    <div class="sales-receipt-form-actions"><button type="submit" class="crm-button crm-button--primary crm-button--md" data-accent="sales">{{ $daySubmission ? 'Simpan agenda tambahan' : 'Tambahkan ke struk' }}</button></div>
                </form>
            </div>
        </div>
    @endif
</section>
