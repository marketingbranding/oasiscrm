@extends('layouts.crm')

@section('title', 'Laporan Fee Sales')

@section('content')
<x-crm.page-header variant="canonical" eyebrow="Laporan" title="Laporan Fee Sales" description="Rekap aktivitas Sales berdasarkan periode dan proyek." />

<form method="GET" action="{{ route('sales-fee-reports.index') }}">
    <x-crm.toolbar label="Filter laporan aktivitas Sales">
        <x-crm.field label="Tanggal Mulai" for="date_from">
            <x-crm.date-field id="date_from" name="date_from" :value="$dateFrom" required />
        </x-crm.field>
        <x-crm.field label="Tanggal Selesai" for="date_to">
            <x-crm.date-field id="date_to" name="date_to" :value="$dateTo" required />
        </x-crm.field>
        <x-crm.field label="Proyek" for="project_id">
            <select id="project_id" name="project_id" class="crm-control">
                <option value="">Semua proyek</option>
                @foreach($projects as $project)
                    <option value="{{ $project->id }}" @selected((string) $projectId === (string) $project->id)>{{ $project->project_name }}</option>
                @endforeach
            </select>
        </x-crm.field>
        <x-crm.field label="Koordinator" for="coordinator_id">
            <select id="coordinator_id" name="coordinator_id" class="crm-control">
                <option value="">Semua koordinator</option>
                @foreach($coordinators as $coordinator)
                    <option value="{{ $coordinator->id }}" @selected((string) $coordinatorId === (string) $coordinator->id)>{{ $coordinator->name }}</option>
                @endforeach
            </select>
        </x-crm.field>
        <x-crm.field label="Sales" for="sales_user_id">
            <select id="sales_user_id" name="sales_user_id" class="crm-control">
                <option value="">Semua Sales</option>
                @foreach($salesUsers as $salesUser)
                    <option value="{{ $salesUser->id }}" @selected((string) $salesUserId === (string) $salesUser->id)>{{ $salesUser->name }}</option>
                @endforeach
            </select>
        </x-crm.field>
        <x-slot:actions>
            <x-crm.button type="submit" variant="primary" accent="reports">Terapkan Filter</x-crm.button>
        </x-slot:actions>
    </x-crm.toolbar>
</form>

<x-crm.section id="sales-fee-report-results" title="Hasil Laporan" description="Aktivitas Sales pada periode terpilih.">
    <div class="crm-table-scroll">
        <table class="crm-data-table">
            <thead>
                <tr><th>Sales</th><th>Koordinator</th><th>Proyek</th><th>Total Agenda</th><th>Agenda Selesai</th><th>Total Lead</th><th>Aksi</th></tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    @php($routeParameters = ['salesUser' => $row->user_id, 'project' => $row->project_id, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'project_id' => $projectId, 'coordinator_id' => $coordinatorId, 'sales_user_id' => $salesUserId])
                    <tr>
                        <td>{{ $row->sales_name }}</td>
                        <td>{{ $row->coordinator_name ?? '-' }}</td>
                        <td>{{ $row->project_name }}</td>
                        <td>{{ $row->agenda_total }}</td>
                        <td>{{ $row->agenda_done }}</td>
                        <td>{{ $row->lead_total }}</td>
                        <td class="crm-actions">
                            <a class="crm-table-action" href="{{ route('sales-fee-reports.show', $routeParameters) }}">Lihat</a>
                            <a class="crm-table-action" href="{{ route('sales-fee-reports.print', $routeParameters) }}" target="_blank" rel="noopener">Cetak</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-crm.empty-state title="Tidak ada Sales pada scope cabang ini." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-crm.section>
@endsection
