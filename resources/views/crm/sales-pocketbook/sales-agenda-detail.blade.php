@extends('layouts.crm')

@section('title', 'Detail Agenda - Buku Saku Saya')
@section('body-class', 'sales-mobile-detail-page')

@section('content')
<div class="sales-pocketbook-workspace sales-agenda-detail space-y-4">
    <nav aria-label="Breadcrumb" class="text-sm font-bold">
        @php
            $returnQuery = ['work_date' => request()->query('return_work_date', $agenda->scheduled_date?->toDateString())];
            if (request()->query('return_view') === 'all') {
                $returnQuery['view'] = 'all';
            }
        @endphp
        <a class="sales-agenda-back-link" href="{{ route('sales-agendas.index', $returnQuery) }}"><span class="sales-agenda-back-link-icon" aria-hidden="true">←</span><span>Kembali</span></a>
    </nav>

    <x-crm.page-header variant="canonical" class="sales-pocketbook-detail-hero" eyebrow="Agenda Sales" :title="$agenda->title">
        <x-slot:actions>
            <x-crm.status-badge :variant="$agenda->isFinished() ? 'success' : 'pending'">
                {{ $agenda->isFinished() ? 'Selesai' : 'Belum selesai' }}
            </x-crm.status-badge>
        </x-slot:actions>
    </x-crm.page-header>

    <section class="sales-agenda-mobile-info" aria-label="Informasi agenda">
        <div class="sales-agenda-mobile-info-top">
            <x-crm.status-badge :variant="$agenda->isFinished() ? 'success' : 'pending'">
                {{ $agenda->isFinished() ? 'Selesai' : 'Belum selesai' }}
            </x-crm.status-badge>
            <span>{{ $agenda->scheduled_date?->locale('id')->translatedFormat('l, d F Y') }}</span>
        </div>
        <h1>{{ $agenda->title }}</h1>
        <div class="sales-agenda-mobile-info-meta">
            <strong>{{ $agenda->sales_activity_category ?: 'Lainnya' }}</strong>
            <span>{{ $agenda->location ?: 'Belum diisi' }}</span>
        </div>
    </section>

    <section class="sales-pocketbook-context-strip sales-agenda-detail-summary" aria-label="Ringkasan agenda">
        <div><span>Tanggal</span><strong>{{ $agenda->scheduled_date?->locale('id')->translatedFormat('l, d F Y') }}</strong></div>
        <div><span>Waktu</span><strong>{{ $agenda->start_time ? substr((string) $agenda->start_time, 0, 5) : 'Fleksibel' }}{{ $agenda->end_time ? '–'.substr((string) $agenda->end_time, 0, 5) : '' }}</strong></div>
        <div><span>Kategori</span><strong>{{ $agenda->sales_activity_category ?: 'Lainnya' }}</strong></div>
        <div><span>Lokasi</span><strong>{{ $agenda->location ?: 'Belum diisi' }}</strong></div>
    </section>

    @if($errors->has('photo'))
        <x-crm.alert variant="error" title="Foto belum tersimpan." id="sales-agenda-photo-error">{{ $errors->first('photo') }}</x-crm.alert>
    @endif

    <x-crm.section id="agenda-evidence" title="Bukti foto" description="Maks. 2" class="sales-pocketbook-screen-section">
        @php
            $activeEvidence = $agenda->evidence->filter(fn ($evidence): bool => ! $evidence->purged_at)->values();
            $canUploadEvidence = $canUploadEvidence && $activeEvidence->count() < 2;
        @endphp
        <div class="sales-agenda-photo-grid">
            @forelse($activeEvidence as $evidence)
                <a href="{{ route('sales-agendas.evidence.show', [$agenda, $evidence]) }}" class="sales-agenda-photo-card" data-agenda-photo-preview>
                    <img src="{{ route('sales-agendas.evidence.show', [$agenda, $evidence]) }}" alt="{{ $evidence->original_name }}" loading="lazy">
                    <span>{{ $evidence->original_name }}</span>
                </a>
            @empty
                <div class="sales-agenda-photo-placeholder" data-agenda-photo-preview role="img" aria-label="Pratinjau foto belum tersedia"><span>Preview foto</span></div>
            @endforelse

            @if($canUploadEvidence)
                <form method="POST" enctype="multipart/form-data" action="{{ route('sales-agendas.evidence.store', $agenda) }}" class="sales-agenda-photo-slot" data-agenda-photo-slot>
                    @csrf
                    <label for="sales-agenda-photo-{{ $activeEvidence->count() }}" class="sales-agenda-photo-picker">
                        <span data-photo-label>+ Foto</span>
                        <small data-photo-state>JPG, PNG, atau WebP</small>
                    </label>
                    <input id="sales-agenda-photo-{{ $activeEvidence->count() }}" type="file" name="photo" accept="image/*" class="sales-agenda-photo-input sr-only" data-photo-input>
                    <button type="button" class="sales-agenda-photo-retry" data-photo-retry hidden>Coba lagi</button>
                </form>
            @elseif($activeEvidence->count() < 2)
                <div class="sales-agenda-photo-slot is-unavailable" aria-hidden="true"><span>+ Foto</span></div>
            @endif
        </div>
        <p class="sales-agenda-photo-count" aria-live="polite">{{ min(2, $activeEvidence->count()) }} dari 2</p>
    </x-crm.section>

    <x-crm.section id="agenda-result" title="Hasil aktivitas" description="Tulis hasil sebelum agenda dikirim ke Koordinator Sales." class="sales-pocketbook-screen-section sales-pocketbook-composer">
        @if(filled($agenda->activity_result))
            <p class="sales-agenda-result-readonly">{{ $agenda->activity_result }}</p>
        @endif
        @if(!$agenda->isFinished())
            <form method="POST" action="{{ route('sales-agendas.update', $agenda) }}" class="sales-agenda-completion-form" data-paper-submit data-completion-form>
                @csrf @method('PATCH')
                <input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($agenda) }}">
                <label for="sales-agenda-result">Hasil aktivitas <span aria-hidden="true">*</span></label>
                <textarea id="sales-agenda-result" class="sales-input" name="activity_result" rows="5" required data-agenda-result>{{ old('activity_result') }}</textarea>
                <p class="sales-agenda-next" role="status">Selanjutnya: {{ $agenda->end_time ? substr((string) $agenda->end_time, 0, 5) : '—' }} agenda berikutnya</p>
                <div class="sales-agenda-sticky-action">
                    <x-crm.button type="submit" variant="primary" accent="sales" data-complete-agenda disabled aria-disabled="true">Selesaikan agenda</x-crm.button>
                </div>
            </form>
        @endif
    </x-crm.section>
</div>
@endsection
