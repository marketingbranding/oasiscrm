@extends('layouts.crm')

@section('title', 'Panduan Buku Saku Sales - Oasis CRM')
@section('body-class', 'sales-pocketbook-onboarding-shell')

@section('content')
<section class="sales-pocketbook-onboarding" x-data="salesPocketbookOnboarding({{ count($steps) }})" @keydown="handleKeydown($event)" :aria-labelledby="`onboarding-title-${step}`">
    <header class="sales-pocketbook-onboarding-header">
        <span class="sales-pocketbook-onboarding-brand">OASIS CRM</span>
        <form method="POST" action="{{ route('sales-pocketbook.onboarding.skip') }}">
            @csrf
            <button type="submit" class="sales-pocketbook-onboarding-skip">Lewati</button>
        </form>
    </header>

    <div class="sales-pocketbook-onboarding-progress" aria-label="Progress onboarding" aria-live="polite">
        <span>Panduan Buku Saku</span>
        <strong x-text="`${step} / {{ count($steps) }}`"></strong>
        <div class="sales-pocketbook-onboarding-dots" aria-hidden="true">
            @foreach($steps as $index => $step)
                <span :class="step === {{ $index + 1 }} ? 'is-active' : ''"></span>
            @endforeach
        </div>
    </div>

    <div class="sales-pocketbook-onboarding-stage" x-ref="step" role="region" aria-live="polite">
        @foreach($steps as $index => $item)
            <article class="sales-pocketbook-onboarding-slide" data-onboarding-step="{{ $index + 1 }}" x-show="step === {{ $index + 1 }}" @if($index > 0) hidden @endif :hidden="step !== {{ $index + 1 }}" x-transition.opacity>
                @if($index === 0)
                    <div class="sales-pocketbook-onboarding-visual sales-pocketbook-onboarding-visual--book" aria-hidden="true">
                        <div class="sales-pocketbook-onboarding-sheet sales-pocketbook-onboarding-sheet--agenda">
                            <strong>Agenda</strong>
                            <span class="is-checked">Canvassing pagi</span>
                            <span class="is-checked">Cek lokasi</span>
                            <span>Follow-up WhatsApp</span>
                            <span>Rapat tim</span>
                        </div>
                        <div class="sales-pocketbook-onboarding-sheet sales-pocketbook-onboarding-sheet--leads">
                            <strong>Lead Saya</strong>
                            <span>Budi Santoso<small>Cek lokasi</small></span>
                            <span>Sari Wulandari<small>Tatap muka</small></span>
                        </div>
                    </div>
                @elseif($index === 1)
                    <div class="sales-pocketbook-onboarding-visual sales-pocketbook-onboarding-visual--agenda" aria-hidden="true">
                        <div class="sales-pocketbook-onboarding-agenda-row">
                            <strong>Kemarin</strong>
                            <span class="sales-pocketbook-onboarding-agenda-card">Telepon konsumen<small>Hasil belum diisi</small></span>
                        </div>
                        <div class="sales-pocketbook-onboarding-agenda-row is-today">
                            <strong>Hari ini</strong>
                            <span class="sales-pocketbook-onboarding-agenda-card">
                                <b>Cek lokasi Jabung</b>
                                <em>Survei akses jalan</em>
                                <em>Foto kavling</em>
                                <small>Tambah foto bukti</small>
                            </span>
                        </div>
                        <div class="sales-pocketbook-onboarding-agenda-row">
                            <strong>Rencana</strong>
                            <span class="sales-pocketbook-onboarding-agenda-card">Event pameran akhir pekan</span>
                        </div>
                    </div>
                @elseif($index === 2)
                    <div class="sales-pocketbook-onboarding-visual sales-pocketbook-onboarding-visual--tear" aria-hidden="true">
                        <span class="sales-pocketbook-onboarding-handoff">Ke Koordinator Sales</span>
                        <div class="sales-pocketbook-onboarding-receipt">
                            <strong>AGENDA SALES</strong>
                            <span>Tgl: 30 Okt 2026</span>
                            <span>Kat: Cek Lokasi</span>
                            <span>Agenda: Cek lokasi Jabung</span>
                            <span>Lokasi: Jabung, Malang</span>
                            <small>Sobek untuk mengirim</small>
                            <i class="sales-pocketbook-onboarding-tear"></i>
                        </div>
                    </div>
                @else
                    <div class="sales-pocketbook-onboarding-visual sales-pocketbook-onboarding-visual--lead" aria-hidden="true">
                        <div class="sales-pocketbook-onboarding-lead-card">
                            <div class="sales-pocketbook-onboarding-lead-heading">
                                <span><strong>Budi Santoso</strong><small>Jabung Malang, 28 Okt</small></span>
                                <b>Belum selesai</b>
                            </div>
                            <div class="sales-pocketbook-onboarding-lead-progress">
                                <span>No Respon</span>
                                <span>Tatap Muka</span>
                                <span>Cek Lokasi</span>
                                <span>Selesai</span>
                            </div>
                            <strong class="sales-pocketbook-onboarding-lead-action">Tandai selesai</strong>
                        </div>
                    </div>
                @endif

                <p class="sales-pocketbook-onboarding-kicker">Langkah {{ $index + 1 }}</p>
                <h1 id="onboarding-title-{{ $index + 1 }}" tabindex="-1">{{ $item['title'] }}</h1>
                <p>{{ $item['description'] }}</p>
            </article>
        @endforeach
    </div>

    <div class="sales-pocketbook-onboarding-actions">
        <button type="button" class="crm-button crm-button--secondary" x-show="step > 1" @if(count($steps) > 1) hidden @endif :hidden="step <= 1" @click="previous()">Kembali</button>
        <button type="button" class="crm-button crm-button--primary" data-accent="sales" x-show="step < {{ count($steps) }}" :hidden="step >= {{ count($steps) }}" @click="next()">Lanjut</button>
        <form method="POST" action="{{ route('sales-pocketbook.onboarding.complete') }}" x-show="step === {{ count($steps) }}" hidden :hidden="step !== {{ count($steps) }}">
            @csrf
            <input type="hidden" name="current_step" value="{{ count($steps) }}">
            <button type="submit" class="crm-button crm-button--primary" data-accent="sales">Mulai bekerja</button>
        </form>
    </div>
</section>
@endsection
