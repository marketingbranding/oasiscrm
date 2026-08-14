@extends('layouts.crm')

@section('title', 'Detail Lead - Oasis CRM')

@section('content')
@php
    $latestVisit = $lead->siteVisits->first();
    $canRecord = Auth::user()->can('recordSiteVisit', $lead);
    $statusVariant = $lead->current_status === \App\Enums\SalesLeadStatus::Akad ? 'success' : 'neutral';
@endphp
<div class="space-y-4">
    <x-crm.page-header variant="canonical" eyebrow="Buku Saku Sales" :title="$lead->customer_name" description="Detail read only Lead dan hasil cek lokasi.">
        <x-slot:meta><x-crm.status-badge :variant="$statusVariant">{{ $lead->current_status->label() }}</x-crm.status-badge></x-slot:meta>
    </x-crm.page-header>

    <nav class="sales-pocketbook-tabs crm-horizontal-tabs" aria-label="Detail Lead">
        <a href="#informasi-lead" class="sales-pocketbook-tab active">Informasi Lead</a>
        @if($lead->shouldShowSiteVisitResults())<a href="#hasil-cek-lokasi" class="sales-pocketbook-tab">Hasil Cek Lokasi</a>@endif
    </nav>

    <x-crm.section id="informasi-lead" title="Informasi Lead">
        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-xs font-bold uppercase">Tanggal Lead</dt><dd>{{ $lead->lead_date->format('d/m/Y') }}</dd></div>
            <div><dt class="text-xs font-bold uppercase">Sales</dt><dd>{{ $lead->sales?->name ?: '-' }}</dd></div>
            <div><dt class="text-xs font-bold uppercase">Cabang / Proyek</dt><dd>{{ $lead->branch?->name ?: '-' }} / {{ $lead->project?->project_name ?: '-' }}</dd></div>
            <div><dt class="text-xs font-bold uppercase">Telepon</dt><dd>{{ $lead->phone ?: '-' }}</dd></div>
            <div><dt class="text-xs font-bold uppercase">Sumber</dt><dd>{{ $lead->effective_source ?: '-' }}</dd></div>
            <div><dt class="text-xs font-bold uppercase">Catatan</dt><dd>{{ $lead->notes ?: '-' }}</dd></div>
        </dl>
    </x-crm.section>

    @if($lead->shouldShowSiteVisitResults())
        <x-crm.section id="hasil-cek-lokasi" title="Hasil Cek Lokasi">
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
    @endif
</div>
@endsection
