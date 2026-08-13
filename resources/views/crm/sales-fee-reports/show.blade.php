@extends('layouts.crm')

@section('title', 'Laporan Fee Sales')

@section('content')
@php
    $periodLabel = \Illuminate\Support\Carbon::parse($dateFrom)->format('d/m/Y').' - '.\Illuminate\Support\Carbon::parse($dateTo)->format('d/m/Y');
    $printParameters = array_merge(request()->only(['date_from', 'date_to', 'project_id', 'coordinator_id', 'sales_user_id']), ['salesUser' => $sales->id, 'project' => $project->id]);
@endphp

<x-crm.page-header variant="canonical" eyebrow="Laporan Fee Sales" title="LAPORAN AKTIVITAS SALES">
    <x-slot:actions>
        <x-crm.button variant="secondary" :href="route('sales-fee-reports.index', request()->only(['date_from', 'date_to', 'project_id', 'coordinator_id', 'sales_user_id']))">Kembali</x-crm.button>
        <x-crm.button variant="primary" accent="reports" :href="route('sales-fee-reports.print', $printParameters)" target="_blank" rel="noopener">Cetak</x-crm.button>
    </x-slot:actions>
</x-crm.page-header>

<x-crm.section id="sales-report-metadata" title="Informasi Laporan">
    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="crm-field-label">Sales</dt><dd>{{ $sales->name }}</dd></div>
        <div><dt class="crm-field-label">Koordinator</dt><dd>{{ $coordinator?->name ?? '-' }}</dd></div>
        <div><dt class="crm-field-label">Cabang</dt><dd>{{ $branch->name }}</dd></div>
        <div><dt class="crm-field-label">Proyek</dt><dd>{{ $project->project_name }}</dd></div>
        <div><dt class="crm-field-label">Periode</dt><dd>{{ $periodLabel }}</dd></div>
    </dl>
</x-crm.section>

<x-crm.section id="sales-report-kpi" title="RINGKASAN">
    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        @foreach(['total_agenda' => 'Total Agenda', 'agenda_done' => 'Agenda Selesai', 'total_lead' => 'Total Lead', 'face_to_face' => 'Tatap Muka', 'site_visit' => 'Cek Lokasi', 'utj' => 'UTJ'] as $key => $label)
            <div class="border border-gray-300 p-3"><dt class="crm-field-label">{{ $label }}</dt><dd class="text-2xl font-bold">{{ $metrics[$key] }}</dd></div>
        @endforeach
    </dl>
</x-crm.section>

<x-crm.section id="sales-report-agendas" title="DETAIL AGENDA" description="Urutan kronologis berdasarkan tanggal agenda.">
    <div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Kategori Aktivitas</th><th>Agenda</th><th>Lokasi</th><th>Hasil</th><th>Status</th></tr></thead><tbody>
        @forelse($agendas as $agenda)
            <tr><td>{{ $agenda->scheduled_date?->format('d/m/Y') ?? '-' }}</td><td>{{ $agenda->sales_activity_category ?: '-' }}</td><td>{{ $agenda->title }}</td><td>{{ $agenda->location ?: '-' }}</td><td>{{ $agenda->activity_result ?: '-' }}</td><td>{{ $agendaStatusLabels[$agenda->status] ?? $agenda->status }}</td></tr>
        @empty
            <tr><td colspan="6"><x-crm.empty-state title="Belum ada agenda pada periode ini." /></td></tr>
        @endforelse
    </tbody></table></div>
</x-crm.section>

<x-crm.section id="sales-report-leads" title="DETAIL LEAD" description="Urutan kronologis berdasarkan tanggal lead.">
    <div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Tanggal Lead</th><th>Nama Konsumen</th><th>Sumber Lead</th><th>Kanal Masuk</th><th>Aktivitas Lead</th><th>Status</th><th>Proyek</th></tr></thead><tbody>
        @forelse($leads as $lead)
            <tr><td>{{ $lead->lead_date?->format('d/m/Y') ?? '-' }}</td><td>{{ $lead->customer_name }}</td><td>{{ $lead->effective_source ?: '-' }}</td><td>{{ $lead->platform ?: '-' }}</td><td>{{ $lead->campaign_name ?: '-' }}</td><td>{{ $lead->current_status?->label() ?? '-' }}</td><td>{{ $project->project_name }}</td></tr>
        @empty
            <tr><td colspan="7"><x-crm.empty-state title="Belum ada lead pada periode ini." /></td></tr>
        @endforelse
    </tbody></table></div>
</x-crm.section>
@endsection
