@extends('layouts.crm')

@section('title', 'Detail Agenda - Buku Saku Saya')
@section('body-class', 'sales-mobile-detail-page')

@section('content')
<div class="sales-pocketbook-workspace sales-agenda-detail space-y-4">
    <nav aria-label="Breadcrumb" class="text-sm font-bold">
        <a class="text-[#0000ee] underline" href="{{ route('sales-agendas.index') }}">Buku Saku Saya</a>
        <span aria-hidden="true"> / </span>
        <span>Detail Agenda</span>
    </nav>

    <x-crm.page-header variant="canonical" class="sales-pocketbook-detail-hero" eyebrow="Agenda Sales" :title="$agenda->title">
        <x-slot:actions>
            <x-crm.status-badge :variant="$agenda->isFinished() ? 'success' : 'pending'">
                {{ $agenda->isFinished() ? 'Selesai' : 'Belum selesai' }}
            </x-crm.status-badge>
        </x-slot:actions>
    </x-crm.page-header>

    <section class="sales-pocketbook-context-strip sales-agenda-detail-summary" aria-label="Ringkasan agenda">
        <div><span>Tanggal</span><strong>{{ $agenda->scheduled_date?->locale('id')->translatedFormat('l, d F Y') }}</strong></div>
        <div><span>Waktu</span><strong>{{ $agenda->start_time ? substr((string) $agenda->start_time, 0, 5) : 'Fleksibel' }}{{ $agenda->end_time ? '–'.substr((string) $agenda->end_time, 0, 5) : '' }}</strong></div>
        <div><span>Kategori</span><strong>{{ $agenda->sales_activity_category ?: 'Lainnya' }}</strong></div>
        <div><span>Lokasi</span><strong>{{ $agenda->location ?: 'Belum diisi' }}</strong></div>
    </section>

    <x-crm.section id="agenda-evidence" title="Bukti foto" description="Maksimal dua foto dari ponsel." class="sales-pocketbook-screen-section">
        @php
            $activeEvidence = $agenda->evidence->filter(fn ($evidence): bool => ! $evidence->purged_at)->values();
            $canUploadEvidence = ! $agenda->isFinished() && $activeEvidence->count() < 2;
        @endphp
        @if($errors->has('photo'))
            <x-crm.alert variant="error" title="Foto belum tersimpan.">{{ $errors->first('photo') }}</x-crm.alert>
        @endif
        <div class="sales-agenda-photo-grid">
            @foreach($activeEvidence as $evidence)
                <a href="{{ route('sales-agendas.evidence.show', [$agenda, $evidence]) }}" class="sales-agenda-photo-card">
                    <img src="{{ route('sales-agendas.evidence.show', [$agenda, $evidence]) }}" alt="{{ $evidence->original_name }}" loading="lazy">
                    <span>{{ $evidence->original_name }}</span>
                </a>
            @endforeach

            @if($canUploadEvidence)
                @for($slot = $activeEvidence->count(); $slot < 2; $slot++)
                    <form method="POST" enctype="multipart/form-data" action="{{ route('sales-agendas.evidence.store', $agenda) }}" class="sales-agenda-photo-slot" data-agenda-photo-slot>
                        @csrf
                        <label for="sales-agenda-photo-{{ $slot }}" class="sales-agenda-photo-picker">+ Foto</label>
                        <input id="sales-agenda-photo-{{ $slot }}" type="file" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment" class="sales-agenda-photo-input sr-only" data-photo-input required>
                    </form>
                @endfor
            @endif
        </div>
        <p class="sales-agenda-photo-count" aria-live="polite">{{ min(2, $activeEvidence->count()) }} dari 2</p>
    </x-crm.section>

    <x-crm.section id="agenda-result" title="Hasil aktivitas" description="Tulis hasil sebelum agenda dikirim ke Koordinator Sales." class="sales-pocketbook-screen-section sales-pocketbook-composer">
        @if(filled($agenda->activity_result))
            <p class="sales-agenda-result-readonly">{{ $agenda->activity_result }}</p>
        @endif
        @if(!$agenda->isFinished())
            <form method="POST" action="{{ route('sales-agendas.update', $agenda) }}" class="sales-agenda-completion-form" data-paper-submit>
                @csrf @method('PATCH')
                <input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($agenda) }}">
                <label for="sales-agenda-result">Hasil aktivitas <span aria-hidden="true">*</span></label>
                <textarea id="sales-agenda-result" class="sales-input" name="activity_result" rows="5" required>{{ old('activity_result') }}</textarea>
                @if($nextAgenda)
                    <p class="sales-agenda-next" role="status">Selanjutnya: {{ $nextAgenda->start_time ? substr((string) $nextAgenda->start_time, 0, 5) : 'Fleksibel' }} {{ $nextAgenda->title }}</p>
                @endif
                <div class="sales-agenda-sticky-action">
                    <x-crm.button type="submit" variant="primary" accent="sales">Selesaikan agenda</x-crm.button>
                </div>
            </form>
        @endif
    </x-crm.section>
</div>
@endsection
