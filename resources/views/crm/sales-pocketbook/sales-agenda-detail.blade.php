@extends('layouts.crm')

@section('title', 'Detail Agenda - Buku Saku Saya')

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
        <div class="sales-agenda-photo-grid">
            @forelse($agenda->evidence as $evidence)
                @if($evidence->purged_at)
                    <p>Bukti foto telah diarsipkan.</p>
                @else
                    <a href="{{ route('sales-agendas.evidence.show', [$agenda, $evidence]) }}" class="sales-agenda-photo-card">
                        <img src="{{ route('sales-agendas.evidence.show', [$agenda, $evidence]) }}" alt="{{ $evidence->original_name }}" loading="lazy">
                        <span>{{ $evidence->original_name }}</span>
                    </a>
                @endif
            @empty
                <x-crm.empty-state title="Belum ada foto" description="Tambahkan foto setelah aktivitas berlangsung." />
            @endforelse
        </div>
        @if(!$agenda->isFinished() && $agenda->evidence->count() < 2)
            <form method="POST" enctype="multipart/form-data" action="{{ route('sales-agendas.evidence.store', $agenda) }}" class="mt-4">
                @csrf
                <label for="sales-agenda-detail-photo">Tambah foto</label>
                <input id="sales-agenda-detail-photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment" class="mt-2 block min-h-11 w-full min-w-0 py-2 text-sm" required>
                <x-crm.button type="submit" variant="secondary" class="mt-2">Unggah bukti</x-crm.button>
            </form>
        @endif
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
                <div class="sales-agenda-sticky-action">
                    <x-crm.button type="submit" variant="primary" accent="sales">Selesaikan agenda</x-crm.button>
                </div>
            </form>
        @endif
    </x-crm.section>
</div>
@endsection
