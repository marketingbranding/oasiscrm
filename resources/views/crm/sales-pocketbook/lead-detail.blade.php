@extends('layouts.crm')

@section('title', 'Detail Lead - Oasis CRM')
@section('body-class', 'sales-mobile-detail-page')

@section('content')
@php
    $latestVisit = $lead->siteVisits->first();
    $canRecord = Auth::user()->can('recordSiteVisit', $lead);
    $canComplete = Auth::user()->isSales() && (int) $lead->sales_user_id === (int) Auth::id() && Auth::user()->hasPermission('sales_pocketbook.manage_own');
    $canUpdateLifecycle = Auth::user()->can('updateLifecycleStatus', $lead);
    $canMarkUtj = Auth::user()->can('markUtjDirect', $lead);
    $statusVariant = $lead->current_status === \App\Enums\SalesLeadStatus::Akad ? 'success' : 'neutral';
    $currentStep = match ($lead->current_status) {
        \App\Enums\SalesLeadStatus::NoResponse => 1,
        \App\Enums\SalesLeadStatus::Discussion, \App\Enums\SalesLeadStatus::FaceToFace => 2,
        \App\Enums\SalesLeadStatus::SiteVisit, \App\Enums\SalesLeadStatus::Utj => 3,
        \App\Enums\SalesLeadStatus::SlikCheck, \App\Enums\SalesLeadStatus::SlikRejected => 4,
        \App\Enums\SalesLeadStatus::Akad => 5,
        default => 1,
    };
    if ($lead->completed_at) {
        $currentStep = 5;
    }
    $historyFeed = collect()
        ->concat($lead->statusHistories->map(fn ($history) => [
            'date' => $history->changed_at,
            'label' => $history->status instanceof \App\Enums\SalesLeadStatus ? $history->status->label() : ucfirst(str_replace('_', ' ', (string) $history->status)),
            'detail' => null,
        ]))
        ->concat($lead->siteVisits->filter(fn ($visit) => $visit->is_completed)->map(fn ($visit) => [
            'date' => $visit->visited_at ?? $visit->visit_date,
            'label' => 'Cek Lokasi · '.ucfirst($visit->visit_status),
            'detail' => $visit->notes,
        ]))
        ->filter(fn ($item) => $item['date'] !== null)
        ->sortByDesc(fn ($item) => $item['date']->timestamp)
        ->values();
@endphp

<div class="sales-pocketbook-workspace sales-lead-detail-page">
    <div class="sales-lead-detail-mobile" data-current-step="{{ $currentStep }}">
    <nav aria-label="Breadcrumb" class="sales-lead-detail-back"><a href="{{ route('sales-agendas.index', ['tab' => 'leads']) }}"><span aria-hidden="true">‹</span> Lead Saya</a></nav>

    <header class="sales-lead-detail-mobile-header">
        <p>{{ $lead->branch?->name ?: '-' }} <span aria-hidden="true">·</span> {{ $lead->project?->project_name ?: '-' }}</p>
        <h1>{{ $lead->customer_name }}</h1>
        <x-crm.status-badge :variant="$statusVariant">{{ $lead->current_status->label() }}</x-crm.status-badge>
        @if($lead->completed_at)<span class="sales-lead-detail-independent-status">Selesai</span>@endif
        @if($lead->current_status === \App\Enums\SalesLeadStatus::Freelance)<span class="sales-lead-detail-independent-status">Freelance</span>@endif
    </header>

    <section class="sales-lead-detail-progress" aria-label="Progres lead" data-current-step="{{ $currentStep }}">
        <div class="sales-lead-detail-progress-label"><strong>PROGRES LEAD</strong><span>LANGKAH {{ $currentStep }} DARI 5</span></div>
        <ol>
            @foreach(['No Respon', 'Diskusi', 'Cek Lokasi', 'Cek SLIK', 'Akad'] as $index => $stepLabel)
                <li class="{{ $currentStep >= $index + 1 ? 'is-active' : '' }}" aria-label="{{ $stepLabel }}{{ $currentStep === $index + 1 ? ', saat ini' : '' }}"></li>
            @endforeach
        </ol>
        <div class="sales-lead-detail-progress-steps"><span>No Respon</span><span>Diskusi</span><span>Cek Lokasi</span><span>Cek SLIK</span><span>Akad</span></div>
    </section>

    <section class="sales-lead-detail-information" aria-labelledby="sales-lead-information-title">
        <h2 id="sales-lead-information-title">Informasi</h2>
        <dl>
            <div><dt>Telepon</dt><dd>{{ $lead->phone ?: '-' }}</dd></div>
            <div><dt>Sumber</dt><dd>{{ $lead->effective_source ?: '-' }}</dd></div>
            <div><dt>Tanggal Lead</dt><dd>{{ $lead->lead_date->format('d/m/Y') }}</dd></div>
            <div><dt>Sales</dt><dd>{{ $lead->sales?->name ?: '-' }}</dd></div>
        </dl>
        @if($lead->notes)<p class="sales-lead-detail-note">{{ $lead->notes }}</p>@endif
    </section>

    <section class="sales-lead-detail-history" aria-labelledby="sales-lead-history-title">
        <h2 id="sales-lead-history-title">Riwayat hasil</h2>
        @if($historyFeed->isNotEmpty())
            <ol data-lead-history-list>
                @foreach($historyFeed as $item)
                    <li><strong>{{ $item['label'] }}</strong><time>{{ $item['date']->format('d/m/Y · H:i') }}</time>@if($item['detail'])<p>{{ $item['detail'] }}</p>@endif</li>
                @endforeach
            </ol>
        @else
            <p class="sales-lead-detail-empty">Belum ada riwayat hasil.</p>
        @endif
        @if($canRecord && $lead->shouldShowSiteVisitResults())
            <x-crm.button type="button" variant="secondary" class="mt-3 w-full" @click="$dispatch('oasis:modal-open', { name: 'lead-mobile-site-visit' })">{{ $latestVisit && !$latestVisit->is_completed ? 'Lengkapi Hasil Cek Lokasi' : 'Isi Hasil Cek Lokasi' }}</x-crm.button>
        @endif
    </section>

    <div class="sales-lead-detail-actions" aria-label="Aksi lead">
        @if($canUpdateLifecycle && $lead->current_status->isManual())
            <x-crm.button type="button" variant="primary" accent="sales" @click="$dispatch('oasis:modal-open', { name: 'lead-update-result' })">Update hasil</x-crm.button>
        @endif
        @if($lead->completed_at)
            <span class="sales-lead-detail-completed">Lead selesai</span>
        @elseif($canComplete)
            <form method="POST" action="{{ route('sales-leads.complete', $lead) }}">@csrf @method('PATCH')<input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($lead) }}"><x-crm.button type="submit" variant="secondary">Tandai selesai</x-crm.button></form>
        @endif
        @if(Auth::user()->isSuperadmin())
            <form method="POST" action="{{ route('sales-leads.destroy', $lead) }}" onsubmit="return confirm('Hapus lead ini? Lead tidak akan lagi muncul di data operasional.')">@csrf @method('DELETE')<x-crm.button type="submit" variant="danger">Hapus Lead</x-crm.button></form>
        @endif
    </div>

    @if($canUpdateLifecycle && $lead->current_status->isManual())
        <x-crm.modal name="lead-update-result" title="Update hasil: {{ $lead->customer_name }}" description="Pilih hasil tindak lanjut untuk memperbarui status lead.">
            <div class="grid gap-2">
                @foreach(\App\Enums\SalesLeadStatus::MANUAL as $manualStatus)
                    @if($lead->current_status !== $manualStatus)
                        <x-crm.button type="button" variant="secondary" class="w-full" @click="$dispatch('oasis:modal-open', { name: 'lead-status-confirm-{{ $manualStatus->value }}-{{ $lead->id }}' })">{{ $manualStatus->label() }}</x-crm.button>
                    @endif
                @endforeach
                @if($canMarkUtj)
                    <x-crm.button type="button" variant="secondary" class="w-full" @click="$dispatch('oasis:modal-open', { name: 'lead-utj-confirm-{{ $lead->id }}' })">Tandai UTJ</x-crm.button>
                @endif
            </div>
            <p class="mt-3 text-xs text-gray-600">Waktu status tercatat otomatis saat disimpan.</p>
        </x-crm.modal>
        @foreach(\App\Enums\SalesLeadStatus::MANUAL as $manualStatus)
            @if($lead->current_status !== $manualStatus)
                <x-crm.modal name="lead-status-confirm-{{ $manualStatus->value }}-{{ $lead->id }}" title="Ubah Status: {{ $lead->customer_name }}" description="Konfirmasi perubahan status lead.">
                    <form method="POST" action="{{ route('sales-leads.lifecycle-status.update', $lead) }}" class="space-y-3">
                        @csrf @method('PATCH')
                        <input type="hidden" name="status" value="{{ $manualStatus->value }}">
                        <dl class="grid grid-cols-1 gap-1 text-sm"><div><dt class="font-bold">Status saat ini</dt><dd>{{ $lead->current_status->label() }}</dd></div><div><dt class="font-bold">Status baru</dt><dd>{{ $manualStatus->label() }}</dd></div><div><dt class="font-bold">Tanggal tercatat</dt><dd>{{ now()->format('d/m/Y H:i') }} (otomatis, waktu input)</dd></div></dl>
                        <x-crm.button type="submit" variant="primary" accent="sales">Ya, Ubah Status</x-crm.button>
                    </form>
                </x-crm.modal>
            @endif
        @endforeach
        @if($canMarkUtj)
            <x-crm.modal name="lead-utj-confirm-{{ $lead->id }}" title="Tandai UTJ: {{ $lead->customer_name }}" description="Lead langsung ditandai UTJ tanpa mencatat data cek lokasi atau konsumen.">
                <form method="POST" action="{{ route('sales-leads.utj.store', $lead) }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="operation_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <dl class="grid grid-cols-1 gap-1 text-sm"><div><dt class="font-bold">Status saat ini</dt><dd>{{ $lead->current_status->label() }}</dd></div><div><dt class="font-bold">Status baru</dt><dd>{{ \App\Enums\SalesLeadStatus::Utj->label() }}</dd></div><div><dt class="font-bold">Tanggal tercatat</dt><dd>{{ now()->format('d/m/Y H:i') }} (otomatis, waktu input)</dd></div></dl>
                    <x-crm.button type="submit" variant="primary" accent="sales">Ya, Tandai UTJ</x-crm.button>
                </form>
            </x-crm.modal>
        @endif
    @endif
    @if($canRecord && $lead->shouldShowSiteVisitResults())
        <x-crm.modal name="lead-mobile-site-visit" title="Hasil Cek Lokasi: {{ $lead->customer_name }}" description="Simpan hasil kunjungan pada alur cek lokasi yang sudah berlaku.">
            <form method="POST" action="{{ $latestVisit && !$latestVisit->is_completed ? route('sales-leads.site-visits.update', [$lead, $latestVisit]) : route('sales-leads.site-visits.store', $lead) }}" class="grid gap-3">
                @csrf
                @if($latestVisit && !$latestVisit->is_completed)
                    @method('PATCH')
                @else
                    <input type="hidden" name="operation_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                @endif
                <input type="hidden" name="completion" value="complete">
                <x-crm.field label="Tanggal" for="mobile-site-visit-date" required><x-crm.date-field id="mobile-site-visit-date" name="tanggal" :value="$latestVisit?->visit_date?->toDateString() ?? now()->toDateString()" required /></x-crm.field>
                <x-crm.field label="Waktu" for="mobile-site-visit-time" required><select id="mobile-site-visit-time" name="waktu" class="sales-input" required>@foreach(['pagi' => 'Pagi', 'siang' => 'Siang', 'sore' => 'Sore', 'malam' => 'Malam'] as $value => $label)<option value="{{ $value }}" {{ $latestVisit?->visit_time === $value ? 'selected' : '' }}>{{ $label }}</option>@endforeach</select></x-crm.field>
                <x-crm.field label="Hasil" for="mobile-site-visit-status" required><select id="mobile-site-visit-status" name="status" class="sales-input" required>@foreach(['follow up' => 'Follow Up', 'non ok' => 'Non OK', 'utj' => 'UTJ'] as $value => $label)<option value="{{ $value }}" {{ $latestVisit?->visit_status === $value ? 'selected' : '' }}>{{ $label }}</option>@endforeach</select></x-crm.field>
                <x-crm.field label="Catatan" for="mobile-site-visit-notes"><textarea id="mobile-site-visit-notes" name="keterangan" class="sales-input" rows="3">{{ $latestVisit?->notes }}</textarea></x-crm.field>
                <x-crm.button type="submit" variant="primary" accent="sales">Simpan Hasil Cek Lokasi</x-crm.button>
            </form>
        </x-crm.modal>
    @endif
    </div>

    {{-- Preserve the existing desktop lead-detail layout. --}}
    <div class="sales-lead-detail-desktop">
        <nav aria-label="Breadcrumb" class="mb-4 text-sm font-bold"><a href="{{ route('sales-agendas.index', ['tab' => 'leads']) }}" class="text-[#0000ee] underline">Lead Saya</a><span aria-hidden="true"> / </span><span>Detail Lead</span></nav>
        <x-crm.page-header variant="canonical" class="sales-pocketbook-detail-hero" eyebrow="Buku Saku Sales" :title="$lead->customer_name" description="Detail lead, progres status, dan hasil aktivitas.">
            <x-slot:actions><div class="flex flex-wrap items-center gap-2"><x-crm.status-badge :variant="$lead->completed_at ? 'success' : $statusVariant">{{ $lead->completed_at ? 'Selesai' : $lead->current_status->label() }}</x-crm.status-badge>@if(Auth::user()->isSuperadmin())<form method="POST" action="{{ route('sales-leads.destroy', $lead) }}" onsubmit="return confirm('Hapus lead ini? Lead tidak akan lagi muncul di data operasional.')">@csrf @method('DELETE')<x-crm.button type="submit" variant="danger">Hapus Lead</x-crm.button></form>@endif</div></x-slot:actions>
        </x-crm.page-header>
        <x-crm.section id="desktop-informasi-lead" title="Informasi Lead" class="sales-pocketbook-screen-section">
            <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="text-xs font-bold uppercase">Tanggal Lead</dt><dd>{{ $lead->lead_date->format('d/m/Y') }}</dd></div>
                <div><dt class="text-xs font-bold uppercase">Sales</dt><dd>{{ $lead->sales?->name ?: '-' }}</dd></div>
                <div><dt class="text-xs font-bold uppercase">Cabang / Proyek</dt><dd>{{ $lead->branch?->name ?: '-' }} / {{ $lead->project?->project_name ?: '-' }}</dd></div>
                <div><dt class="text-xs font-bold uppercase">Telepon</dt><dd>{{ $lead->phone ?: '-' }}</dd></div>
                <div><dt class="text-xs font-bold uppercase">Sumber</dt><dd>{{ $lead->effective_source ?: '-' }}</dd></div>
                <div><dt class="text-xs font-bold uppercase">Catatan</dt><dd>{{ $lead->notes ?: '-' }}</dd></div>
            </dl>
            <div class="mt-5 border-t border-black/20 pt-4">
                <h3 class="text-xs font-bold uppercase">Progres status</h3>
                <ol class="sales-lead-status-history mt-2">
                    @forelse($lead->statusHistories as $history)
                        <li><strong>{{ $history->status instanceof \App\Enums\SalesLeadStatus ? $history->status->label() : ucfirst(str_replace('_', ' ', (string) $history->status)) }}</strong><span>{{ $history->changed_at?->format('d/m/Y H:i') }}</span></li>
                    @empty
                        <li><span>Belum ada riwayat status.</span></li>
                    @endforelse
                </ol>
            </div>
            @if($lead->completed_at)
                <p class="mt-4 sales-lead-completed-note">Lead ditandai selesai pada {{ $lead->completed_at->format('d/m/Y H:i') }}.</p>
            @elseif($canComplete)
                <form method="POST" action="{{ route('sales-leads.complete', $lead) }}" class="mt-4">@csrf @method('PATCH')<input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($lead) }}"><x-crm.button type="submit" variant="primary" accent="sales">Tandai selesai</x-crm.button></form>
            @endif
        </x-crm.section>
        @if($lead->shouldShowSiteVisitResults())
            <x-crm.section id="hasil-cek-lokasi" title="Hasil Cek Lokasi" class="sales-pocketbook-screen-section sales-pocketbook-composer">
                @if(!$latestVisit)
                    <x-crm.empty-state title="Hasil cek lokasi belum diisi." />
                    @if($canRecord)
                        <div class="mt-3 flex flex-wrap gap-2">
                            <x-crm.button type="button" variant="primary" accent="sales" @click="$dispatch('oasis:modal-open', { name: 'new-site-visit' })">Isi Hasil Cek Lokasi</x-crm.button>
                            <form method="POST" action="{{ route('sales-leads.site-visits.store', $lead) }}">@csrf<input type="hidden" name="completion" value="isi_nanti"><input type="hidden" name="operation_uuid" value="{{ Illuminate\Support\Str::uuid() }}"><x-crm.button type="submit" variant="secondary">Isi Nanti</x-crm.button></form>
                        </div>
                    @endif
                @else
                    <h3 class="mb-2 text-xs font-bold uppercase">Hasil Cek Lokasi Terakhir</h3>
                    <div class="border border-black/20 p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2"><strong>{{ $latestVisit->visit_date?->translatedFormat('d F Y') ?: 'Tanggal belum diisi' }}</strong><x-crm.status-badge :variant="$latestVisit->is_completed ? 'success' : 'pending'">{{ $latestVisit->is_completed ? ucfirst($latestVisit->visit_status) : 'Belum diisi' }}</x-crm.status-badge></div>
                        <p class="mt-2">Waktu: {{ $latestVisit->visit_time ? ucfirst($latestVisit->visit_time) : '-' }}</p><p>Catatan: {{ $latestVisit->notes ?: '-' }}</p>
                    </div>
                    @if($canRecord)<div class="mt-3 flex gap-2"><x-crm.button type="button" variant="primary" accent="sales" @click="$dispatch('oasis:modal-open', { name: '{{ $latestVisit->is_completed ? 'edit-site-visit' : 'complete-site-visit' }}' })">{{ $latestVisit->is_completed ? 'Edit Hasil' : 'Lengkapi Hasil' }}</x-crm.button>@if($latestVisit->is_completed)<x-crm.button type="button" variant="secondary" @click="$dispatch('oasis:modal-open', { name: 'new-site-visit' })">Tambah Cek Lokasi</x-crm.button>@endif</div>@endif
                    <h3 class="mb-2 mt-5 text-xs font-bold uppercase">Riwayat</h3>
                    <div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Waktu</th><th>Hasil</th><th>Catatan</th></tr></thead><tbody>@foreach($lead->siteVisits as $visit)<tr><td>{{ $visit->visit_date?->format('d/m/Y') ?: '-' }}</td><td>{{ $visit->visit_time ? ucfirst($visit->visit_time) : '-' }}</td><td>{{ $visit->is_completed ? ucfirst($visit->visit_status) : 'Belum diisi' }}</td><td>{{ $visit->notes ?: '-' }}</td></tr>@endforeach</tbody></table></div>
                @endif
            </x-crm.section>
        @endif
        @if($canRecord)
            @foreach(['new-site-visit' => null, 'complete-site-visit' => $latestVisit, 'edit-site-visit' => $latestVisit] as $modal => $visit)
                @if(!$visit || $latestVisit)
                    <x-crm.modal :name="$modal" :title="$visit ? ($visit->is_completed ? 'Edit Hasil Cek Lokasi' : 'Lengkapi Hasil Cek Lokasi') : 'Tambah Cek Lokasi'">
                        <form method="POST" action="{{ $visit ? route('sales-leads.site-visits.update', [$lead, $visit]) : route('sales-leads.site-visits.store', $lead) }}" class="grid gap-3 sm:grid-cols-2">
                            @csrf @if($visit) @method('PATCH') @else <input type="hidden" name="operation_uuid" value="{{ Illuminate\Support\Str::uuid() }}"> @endif
                            <input type="hidden" name="completion" value="complete">
                            <x-crm.field label="Tanggal" :for="$modal.'-date'" required><x-crm.date-field :id="$modal.'-date'" name="tanggal" :value="$visit?->visit_date?->toDateString() ?? now()->toDateString()" required /></x-crm.field>
                            <x-crm.field label="Waktu" :for="$modal.'-time'" required><select id="{{ $modal.'-time' }}" name="waktu" class="sales-input" required>@foreach(['pagi' => 'Pagi', 'siang' => 'Siang', 'sore' => 'Sore', 'malam' => 'Malam'] as $value => $label)<option value="{{ $value }}" {{ $visit?->visit_time === $value ? 'selected' : '' }}>{{ $label }}</option>@endforeach</select></x-crm.field>
                            <x-crm.field label="Hasil" :for="$modal.'-status'" required><select id="{{ $modal.'-status' }}" name="status" class="sales-input" required>@foreach(['follow up' => 'Follow Up', 'non ok' => 'Non OK', 'utj' => 'UTJ'] as $value => $label)<option value="{{ $value }}" {{ $visit?->visit_status === $value ? 'selected' : '' }}>{{ $label }}</option>@endforeach</select></x-crm.field>
                            <x-crm.field label="Catatan" :for="$modal.'-notes'"><textarea id="{{ $modal.'-notes' }}" name="keterangan" class="sales-input" rows="3">{{ $visit?->notes }}</textarea></x-crm.field>
                            <div class="sm:col-span-2"><x-crm.button type="submit" variant="primary" accent="sales">Simpan Hasil</x-crm.button></div>
                        </form>
                    </x-crm.modal>
                @endif
            @endforeach
        @endif
    </div>
</div>
@endsection
