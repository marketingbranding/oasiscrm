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

    <x-crm.section id="agenda-evidence" title="Bukti foto" description="Minimal 1 foto, maksimal 2" class="sales-pocketbook-screen-section">
        @php
            $activeEvidence = $agenda->evidence->filter(fn ($evidence): bool => ! $evidence->purged_at)->values();
            $canUploadEvidence = $canMutateEvidence && $activeEvidence->count() < 2;
            $deletableEvidence = $activeEvidence->filter(fn ($evidence): bool => in_array((int) $evidence->id, $deletableEvidenceIds, true))->values();
        @endphp
        <div class="sales-agenda-photo-grid">
            @forelse($activeEvidence as $evidence)
                <div class="sales-agenda-photo-card">
                    <a href="{{ route('sales-agendas.evidence.show', [$agenda, $evidence]) }}" class="sales-agenda-photo-link" data-agenda-photo-preview>
                        <img src="{{ route('sales-agendas.evidence.show', [$agenda, $evidence]) }}" alt="{{ $evidence->original_name }}" loading="lazy">
                        <span>{{ $evidence->original_name }}</span>
                    </a>
                    @if($deletableEvidence->contains('id', $evidence->id))
                        <x-crm.icon-button
                            type="button"
                            variant="danger"
                            label="Hapus foto {{ $evidence->original_name }}"
                            title="Hapus foto"
                            class="sales-agenda-photo-delete"
                            data-agenda-photo-delete="true"
                            @click="$dispatch('oasis:modal-open', { name: 'delete-agenda-evidence-{{ $evidence->id }}', trigger: $el })"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg>
                        </x-crm.icon-button>
                    @endif
                </div>
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
        @if($deletableEvidence->isNotEmpty())
            @foreach($deletableEvidence as $evidence)
                <x-crm.modal
                    name="delete-agenda-evidence-{{ $evidence->id }}"
                    title="Hapus foto agenda"
                    description="Foto yang dihapus tidak dapat dipulihkan dari agenda ini."
                    size="sm"
                >
                    <p class="text-sm">Hapus foto <strong>{{ $evidence->original_name }}</strong> dari agenda ini?</p>
                    <form method="POST" action="{{ route('sales-agendas.evidence.destroy', [$agenda, $evidence]) }}" class="mt-4">
                        @csrf @method('DELETE')
                        <div class="flex flex-wrap justify-end gap-2">
                            <x-crm.button type="button" variant="secondary" @click="$dispatch('oasis:modal-close', { name: 'delete-agenda-evidence-{{ $evidence->id }}' })">Batal</x-crm.button>
                            <x-crm.button type="submit" variant="danger">Hapus foto</x-crm.button>
                        </div>
                    </form>
                </x-crm.modal>
            @endforeach
        @endif
        <p class="sales-agenda-photo-count" aria-live="polite">{{ min(2, $activeEvidence->count()) }} dari 2</p>
    </x-crm.section>

    <x-crm.section id="agenda-result" title="Hasil aktivitas" description="Tulis hasil sebelum agenda dikirim ke Koordinator Sales." class="sales-pocketbook-screen-section sales-pocketbook-composer">
        @if(filled($agenda->activity_result))
            <p class="sales-agenda-result-readonly">{{ $agenda->activity_result }}</p>
        @endif
        @if(!$agenda->isFinished())
            <form method="POST" action="{{ route('sales-agendas.update', $agenda) }}" class="sales-agenda-completion-form" data-paper-submit data-completion-form data-evidence-count="{{ $activeEvidence->count() }}">
                @csrf @method('PATCH')
                <input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($agenda) }}">
                <label for="sales-agenda-result">Hasil aktivitas <span aria-hidden="true">*</span></label>
                <textarea id="sales-agenda-result" class="sales-input" name="activity_result" rows="5" required data-agenda-result>{{ old('activity_result') }}</textarea>
                @if($activeEvidence->isEmpty())
                    <p id="sales-agenda-photo-required" class="text-sm font-bold text-[var(--oasis-danger)]" role="status">Bukti foto wajib diunggah sebelum agenda diselesaikan.</p>
                @endif
                @if($nextAgenda)
                    <p class="sales-agenda-next" role="status">Selanjutnya: {{ $nextAgenda->start_time ? substr((string) $nextAgenda->start_time, 0, 5) : 'Fleksibel' }} {{ $nextAgenda->title }}</p>
                @endif
                <div class="sales-agenda-sticky-action">
                    <x-crm.button type="submit" variant="primary" accent="sales" data-complete-agenda disabled aria-disabled="true">Selesaikan agenda</x-crm.button>
                </div>
            </form>
        @endif
    </x-crm.section>
</div>
@endsection
