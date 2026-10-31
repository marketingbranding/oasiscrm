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

    @if($errors->has('photo'))
        <x-crm.alert variant="error" title="Foto belum tersimpan." id="sales-agenda-photo-error">{{ $errors->first('photo') }}</x-crm.alert>
    @endif

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
            <div class="mt-4 grid gap-3 sm:grid-cols-2" role="group" aria-labelledby="sales-agenda-photo-actions-title">
                <h3 id="sales-agenda-photo-actions-title" class="sr-only">Tambah bukti foto</h3>
                <form method="POST" enctype="multipart/form-data" action="{{ route('sales-agendas.evidence.store', $agenda) }}" class="border-2 border-black bg-white p-3">
                    @csrf
                    <label for="sales-agenda-camera-photo" class="block font-[Helvetica] text-sm font-bold">Ambil Foto</label>
                    <p class="mt-1 text-xs">Gunakan kamera perangkat, bila tersedia.</p>
                    <input id="sales-agenda-camera-photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment" class="mt-2 block min-h-11 w-full min-w-0 py-2 text-sm" required>
                    <x-crm.button type="submit" variant="secondary" class="mt-2 w-full">Simpan Foto</x-crm.button>
                </form>
                <form method="POST" enctype="multipart/form-data" action="{{ route('sales-agendas.evidence.store', $agenda) }}" class="border-2 border-black bg-white p-3">
                    @csrf
                    <label for="sales-agenda-upload-photo" class="block font-[Helvetica] text-sm font-bold">Upload Foto</label>
                    <p class="mt-1 text-xs">Pilih foto yang sudah tersimpan di perangkat.</p>
                    <input id="sales-agenda-upload-photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="mt-2 block min-h-11 w-full min-w-0 py-2 text-sm" required>
                    <x-crm.button type="submit" variant="secondary" class="mt-2 w-full">Simpan Foto</x-crm.button>
                </form>
            </div>
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
