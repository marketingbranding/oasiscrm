@php
    $previousAgendaDate = $agendaDate->subDay();
    $nextAgendaDate = $agendaDate->addDay();
    $today = \Carbon\CarbonImmutable::today(config('app.timezone'));
    $agendaStatuses = [
        'planned' => ['Menunggu', 'pending'],
        'confirmed' => ['Terkirim ke Koordinator', 'info'],
        'done' => ['Selesai', 'success'],
        'cancelled' => ['Dibatalkan', 'inactive'],
        'rescheduled' => ['Ditunda ke besok', 'warning'],
    ];
@endphp

<section id="agenda-list" class="sales-receipt-workspace" aria-labelledby="agenda-receipt-title">
    <div class="sales-receipt-datebar">
        <a href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $previousAgendaDate->toDateString()]) }}" class="sales-receipt-date-link">← Kemarin</a>
        <div><span>Agenda</span><h2 id="agenda-receipt-title">{{ $agendaDate->locale('id')->translatedFormat('l, d F Y') }}</h2></div>
        <a href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $nextAgendaDate->toDateString()]) }}" class="sales-receipt-date-link">Besok →</a>
    </div>

    <div class="sales-receipt-contexts" aria-label="Pilihan hari agenda">
        <a href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $today->subDay()->toDateString()]) }}" class="{{ $agendaDate->isSameDay($today->subDay()) ? 'is-active' : '' }}">Kemarin</a>
        <a href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $today->toDateString()]) }}" class="{{ $agendaDate->isSameDay($today) ? 'is-active' : '' }}">Hari ini</a>
        <a href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $today->addDay()->toDateString()]) }}" class="{{ $agendaDate->isSameDay($today->addDay()) ? 'is-active' : '' }}">Besok</a>
        <a href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $today->addDays(2)->toDateString()]) }}" class="{{ $agendaDate->greaterThan($today) ? 'is-active' : '' }}">Rencana</a>
    </div>

    @if($incompletePreviousAgendas->isNotEmpty() && $agendaDate->isSameDay($today))
        <div class="sales-receipt-warning" role="status"><strong>Agenda kemarin belum selesai</strong><span>{{ $incompletePreviousAgendas->count() }} agenda perlu ditinjau sebelum memulai hari ini.</span><a href="{{ route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $previousAgendaDate->toDateString()]) }}">Tinjau agenda kemarin</a></div>
    @endif

    <div class="sales-receipt-summary" aria-label="Ringkasan target agenda harian">
        <div><span>Target harian</span><strong>{{ $receiptSummary['normal_minutes'] }} <small>/ {{ $receiptSummary['target_minutes'] }} menit</small></strong></div>
        <div><span>Sisa waktu</span><strong>{{ $receiptSummary['remaining_minutes'] }} menit</strong></div>
        <div><span>Agenda</span><strong>{{ $receiptSummary['agenda_count'] }} <small>terjadwal</small></strong></div>
        @if($daySubmission)<x-crm.status-badge variant="info">Terkirim ke Koordinator</x-crm.status-badge>@elseif($receiptSummary['normal_minutes'] === $receiptSummary['target_minutes'])<x-crm.status-badge variant="success">Siap dikirim</x-crm.status-badge>@else<x-crm.status-badge variant="pending">Menunggu</x-crm.status-badge>@endif
    </div>

    @if($daySubmission)
        <div class="sales-receipt-submitted" role="status"><strong>Struk agenda sudah dikirim ke Koordinator.</strong><span>Waktu, judul, kategori, dan lokasi agenda terjadwal tidak dapat diubah. Hasil dan bukti foto tetap dapat ditambahkan.</span></div>
    @endif

    @if($project)
        <div id="agenda-input" class="sales-receipt-add-panel">
            <button type="button" class="sales-receipt-add-trigger" @click="openAgendaSheet($event)" aria-haspopup="dialog" aria-controls="sales-agenda-create-sheet"><span>+ Tambah agenda</span><small>{{ $daySubmission ? 'Agenda tambahan di luar target 420 menit' : 'Masukkan aktivitas ke struk hari ini' }}</small></button>
        </div>
        <div x-show="agendaOpen" x-cloak class="sales-sheet-backdrop" @click.self="closeSheet()" @keydown="handleSheetKeydown($event)" role="dialog" aria-modal="true" aria-labelledby="sales-agenda-create-title" aria-describedby="sales-agenda-create-description">
            <div id="sales-agenda-create-sheet" x-ref="agendaPanel" class="sales-bottom-sheet">
                <div class="sales-bottom-sheet-header"><div><span class="sales-bottom-sheet-eyebrow">Agenda {{ $agendaDate->locale('id')->translatedFormat('d F Y') }}</span><h2 id="sales-agenda-create-title">Tambah agenda</h2></div><button type="button" x-ref="sheetClose" @click="closeSheet()" class="sales-bottom-sheet-close" aria-label="Tutup form agenda">&times;</button></div>
                <p id="sales-agenda-create-description" class="sales-bottom-sheet-description">{{ $daySubmission ? 'Agenda ini dicatat sebagai pekerjaan tambahan di luar target 420 menit.' : 'Susun aktivitas dengan waktu mulai dan durasi yang jelas.' }}</p>
            <form method="POST" action="{{ route('sales-agendas.store') }}" class="sales-receipt-form">
                @csrf
                <input type="hidden" name="scheduled_date" value="{{ $agendaDate->toDateString() }}">
                <x-crm.field label="Kategori" for="sales-agenda-category" required><select id="sales-agenda-category" class="sales-input" name="sales_activity_category" required><option value="">Pilih kategori</option>@foreach(\App\Models\ContentItem::SALES_ACTIVITY_CATEGORIES as $category)<option value="{{ $category }}" @selected(old('sales_activity_category') === $category)>{{ $category }}</option>@endforeach</select></x-crm.field>
                <x-crm.field label="Judul Agenda" for="sales-agenda-title" required><input id="sales-agenda-title" class="sales-input" name="title" value="{{ old('title') }}" required></x-crm.field>
                <x-crm.field label="Mulai" for="sales-agenda-start-time" required><x-crm.time-field id="sales-agenda-start-time" name="start_time" :value="old('start_time')" required /></x-crm.field>
                <x-crm.field label="Durasi" for="sales-agenda-duration" required><select id="sales-agenda-duration" class="sales-input" name="duration_minutes" required @change="syncDuration($event.target.value)"><option value="">Pilih durasi</option>@foreach([30, 60, 90, 120] as $duration)<option value="{{ $duration }}">{{ $duration }} menit</option>@endforeach</select><span class="sales-field-preview" data-end-preview aria-live="polite">Waktu selesai akan dihitung dari jam mulai.</span></x-crm.field>
                <x-crm.field label="Selesai" for="sales-agenda-end-time" required><x-crm.time-field id="sales-agenda-end-time" name="end_time" :value="old('end_time')" required /></x-crm.field>
                <x-crm.field label="Lokasi" for="sales-agenda-location"><input id="sales-agenda-location" class="sales-input" name="location" value="{{ old('location') }}"></x-crm.field>
                <x-crm.field label="Hasil awal" for="sales-agenda-result" hint="Opsional; isi setelah kegiatan bila belum tersedia." class="md:col-span-2"><textarea id="sales-agenda-result" class="sales-input" name="activity_result" rows="2">{{ old('activity_result') }}</textarea></x-crm.field>
                <div class="sales-receipt-form-actions"><button type="submit" class="crm-button crm-button--primary crm-button--md" data-accent="sales">{{ $daySubmission ? 'Simpan agenda tambahan' : 'Simpan agenda' }}</button></div>
            </form>
            </div>
        </div>
    @else
        <x-crm.alert variant="warning" title="Penugasan proyek diperlukan">Proyek utama belum ditentukan. Hubungi admin untuk menetapkan proyek sebelum membuat agenda.</x-crm.alert>
    @endif

    <div class="sales-receipt-paper">
        <div class="sales-receipt-paper-heading"><span>Agenda Buku Saku · Struk Agenda</span><span>{{ $agendaDate->format('d.m.Y') }}</span></div>
        <ol class="sales-receipt-list">
            @forelse($agendas as $agenda)
                @php
                    $isRunning = false;
                    if ($agenda->start_time && $agenda->end_time && $agenda->scheduled_date) {
                        $startsAt = \Carbon\CarbonImmutable::parse($agenda->scheduled_date->format('Y-m-d').' '.substr((string) $agenda->start_time, 0, 5), config('app.timezone'));
                        $endsAt = \Carbon\CarbonImmutable::parse($agenda->scheduled_date->format('Y-m-d').' '.substr((string) $agenda->end_time, 0, 5), config('app.timezone'));
                        $isRunning = $agenda->status !== 'done' && now(config('app.timezone'))->betweenIncluded($startsAt, $endsAt);
                    }
                    [$agendaStatusLabel, $agendaStatusVariant] = $isRunning ? ['Berjalan', 'info'] : ($agendaStatuses[$agenda->status] ?? [ucfirst(str_replace('_', ' ', $agenda->status)), 'neutral']);
                @endphp
                <li class="sales-receipt-row {{ $agenda->is_outside_schedule ? 'is-additional' : '' }} {{ $agenda->status === 'done' ? 'is-completed' : '' }}" data-agenda-paper-item>
                    <div class="sales-receipt-row-time">{{ $agenda->start_time ? substr((string) $agenda->start_time, 0, 5) : '--:--' }}<span>{{ $agenda->end_time ? substr((string) $agenda->end_time, 0, 5) : 'fleksibel' }}</span></div>
                    <div class="sales-receipt-row-main">
                        <div class="sales-receipt-row-meta">
                            <span>{{ $agenda->duration_minutes ? $agenda->duration_minutes.' menit' : 'Waktu fleksibel' }}</span>
                            @if($agenda->sales_activity_category)<span>{{ $agenda->sales_activity_category }}</span>@endif
                        </div>
                        <h3>{{ $agenda->title }}</h3>
                        @if($agenda->location)<p>{{ $agenda->location }}</p>@endif
                        @if($agenda->is_outside_schedule)<strong class="sales-receipt-extra-label">Agenda tambahan · di luar target</strong>@endif
                    </div>
                    <x-crm.status-badge :variant="$agendaStatusVariant">{{ $agendaStatusLabel }}</x-crm.status-badge>
                    <a class="sales-receipt-row-link" href="{{ route('sales-agendas.show', $agenda) }}">Buka</a>
                </li>
            @empty
                <li class="sales-receipt-empty"><strong>Belum ada agenda</strong><span>Tambahkan aktivitas pertama untuk mengisi struk kerja hari ini.</span></li>
            @endforelse
        </ol>
        <div class="sales-receipt-paper-total"><span>Total agenda terjadwal</span><strong>{{ $receiptSummary['normal_minutes'] }} / {{ $receiptSummary['target_minutes'] }} menit</strong></div>
        @if($receiptSummary['extra_count'] > 0)<div class="sales-receipt-paper-extra">{{ $receiptSummary['extra_count'] }} agenda tambahan tidak dihitung dalam target 420 menit.</div>@endif
    </div>

    @if(!$daySubmission && $receiptSummary['normal_minutes'] === $receiptSummary['target_minutes'])
        <form method="POST" action="{{ route('sales-agendas.submit') }}" class="sales-receipt-submit-form">
            @csrf
            <input type="hidden" name="scheduled_date" value="{{ $agendaDate->toDateString() }}">
            <button type="submit" class="crm-button crm-button--primary crm-button--lg" data-accent="sales">Sobek &amp; kirim ke Koordinator</button>
        </form>
    @endif
    <x-crm.pagination :collection="$agendas" :show-per-page="false" />
</section>
