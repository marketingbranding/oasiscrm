@extends('layouts.crm')

@section('title', 'Buku Saku Sales - Monitoring Admin Cabang')

@section('content')
@php
    $tab = in_array($tab ?? request('tab'), ['leads', 'agenda'], true) ? ($tab ?? request('tab')) : 'leads';
    $preservedQuery = request()->except(['tab', 'lead_page', 'agenda_page']);
    $tabUrl = fn (string $target) => route('sales-pocketbook.index', array_filter(array_merge($preservedQuery, ['tab' => $target]), fn ($value) => filled($value)));
    $agendaStatuses = [
        'planned' => ['Direncanakan', 'pending'],
        'confirmed' => ['Dikonfirmasi', 'info'],
        'done' => ['Selesai', 'success'],
        'cancelled' => ['Dibatalkan', 'inactive'],
        'rescheduled' => ['Dijadwalkan Ulang', 'warning'],
    ];
    $leadData = $leads->getCollection()->mapWithKeys(fn ($lead) => [(string) $lead->id => [
        'customer_name' => $lead->customer_name,
        'phone' => $lead->phone ?: '-',
        'notes' => $lead->notes ?: '-',
        'status' => $lead->current_status?->label() ?? '-',
        'latest_activity' => $lead->latest_activity_at?->format('d/m/Y H:i') ?? '-',
        'lifecycle' => ($lead->latest_activity_status?->label() ?? $lead->current_status?->label() ?? '-').' · '.($lead->latest_activity_at?->format('d/m/Y H:i') ?? '-'),
    ]]);
@endphp
<div class="space-y-4" x-data="{ selectedLead: null, leads: @js($leadData) }">
    <x-crm.page-header variant="canonical" eyebrow="BUKU SAKU SALES" title="Buku Saku Sales" description="Admin Cabang · Monitoring Read Only">
        <x-slot:meta><x-crm.status-badge variant="inactive">ADMIN CABANG · MONITORING READ ONLY</x-crm.status-badge></x-slot:meta>
        <x-slot:actions><x-crm.button variant="secondary" :href="route('sales-fee-reports.index')">Laporan Fee Sales</x-crm.button></x-slot:actions>
    </x-crm.page-header>

    <nav class="sales-pocketbook-tabs crm-horizontal-tabs" data-horizontal-tabs aria-label="Monitoring Buku Saku Sales">
        @foreach(['leads' => 'Leads', 'agenda' => 'Agenda'] as $key => $label)
            <a href="{{ $tabUrl($key) }}" @if($tab === $key) aria-current="page" @endif class="sales-pocketbook-tab {{ $tab === $key ? 'active' : '' }}">{{ $label }}</a>
        @endforeach
    </nav>

    <x-crm.section id="admin-monitoring-filters" title="Filter Monitoring" :description="$period['from']->format('d/m/Y').' - '.$period['to']->format('d/m/Y')">
        <x-crm.toolbar label="Filter monitoring Admin Cabang">
            <form method="GET" class="grid w-full gap-3 md:grid-cols-3 xl:grid-cols-5">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <x-crm.field label="Periode" for="admin-period"><select id="admin-period" name="period" class="sales-input"><option value="{{ $period['month'] }}">{{ $period['from']->translatedFormat('F Y') }}</option></select></x-crm.field>
                <x-crm.field label="Dari" for="admin-date-from"><x-crm.date-field id="admin-date-from" name="date_from" :value="$filters['date_from'] ?? null" /></x-crm.field>
                <x-crm.field label="Sampai" for="admin-date-to"><x-crm.date-field id="admin-date-to" name="date_to" :value="$filters['date_to'] ?? null" /></x-crm.field>
                <x-crm.field label="Proyek" for="admin-project"><select id="admin-project" name="project_id" class="sales-input"><option value="">Semua Proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((string) ($filters['project_id'] ?? '') === (string) $project->id)>{{ $project->project_name }}</option>@endforeach</select></x-crm.field>
                <x-crm.field label="Koordinator" for="admin-coordinator"><select id="admin-coordinator" name="coordinator_id" class="sales-input"><option value="">Semua Koordinator</option>@foreach($coordinators as $coordinator)<option value="{{ $coordinator->id }}" @selected((string) ($filters['coordinator_id'] ?? '') === (string) $coordinator->id)>{{ $coordinator->name }}</option>@endforeach</select></x-crm.field>
                <x-crm.field label="Sales" for="admin-sales"><select id="admin-sales" name="sales_user_id" class="sales-input"><option value="">Semua Sales</option>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" @selected((string) ($filters['sales_user_id'] ?? '') === (string) $sales->id)>{{ $sales->name }}</option>@endforeach</select></x-crm.field>
                @if($tab === 'leads')
                    <x-crm.field label="Sumber Lead" for="admin-source"><select id="admin-source" name="source" class="sales-input"><option value="">Semua Sumber</option>@foreach($sourceOptions as $source)<option value="{{ $source }}" @selected(($filters['source'] ?? '') === $source)>{{ $source }}</option>@endforeach</select></x-crm.field>
                    <x-crm.field label="Kanal / Platform" for="admin-platform"><select id="admin-platform" name="platform" class="sales-input"><option value="">Semua Kanal</option>@foreach($platformOptions as $platform)<option value="{{ $platform }}" @selected(($filters['platform'] ?? '') === $platform)>{{ $platform }}</option>@endforeach</select></x-crm.field>
                    <x-crm.field label="Status Lead" for="admin-lead-status"><select id="admin-lead-status" name="status" class="sales-input"><option value="">Semua Status</option>@foreach($statusOptions as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ \App\Enums\SalesLeadStatus::from($status)->label() }}</option>@endforeach</select></x-crm.field>
                @else
                    <x-crm.field label="Kategori" for="admin-category"><select id="admin-category" name="agenda_category" class="sales-input"><option value="">Semua Kategori</option>@foreach($agendaCategoryOptions as $category)<option value="{{ $category }}" @selected(($filters['agenda_category'] ?? '') === $category)>{{ $category }}</option>@endforeach</select></x-crm.field>
                    <x-crm.field label="Status Agenda" for="admin-agenda-status"><select id="admin-agenda-status" name="agenda_status" class="sales-input"><option value="">Semua Status</option>@foreach($agendaStatuses as $value => [$label])<option value="{{ $value }}" @selected(($filters['agenda_status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></x-crm.field>
                @endif
                <div class="self-end"><x-crm.button type="submit" variant="primary" accent="sales">Terapkan Filter</x-crm.button></div>
            </form>
        </x-crm.toolbar>
    </x-crm.section>

    @if($tab === 'leads')
        <x-crm.section id="admin-monitoring-leads" title="Lead Cabang" description="Data Lead dalam cakupan cabang Anda.">
            <div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Tanggal Lead</th><th>Nama Konsumen</th><th>Sales</th><th>Koordinator</th><th>Proyek</th><th>Sumber</th><th>Kanal / Aktivitas</th><th>Status Lead</th><th>Aktivitas Terbaru</th><th>Detail</th></tr></thead><tbody>@forelse($leads as $lead)<tr><td>{{ $lead->lead_date->format('d/m/Y') }}</td><td>{{ $lead->customer_name }}</td><td>{{ $lead->sales?->name ?: '-' }}</td><td>{{ collect($coordinatorNamesBySalesId[$lead->sales_user_id] ?? [])->join(', ') ?: '-' }}</td><td>{{ $lead->project?->project_name ?: '-' }}</td><td>{{ $lead->effective_source ?: '-' }}</td><td>{{ collect([$lead->platform, $lead->campaign_name])->filter()->join(' / ') ?: '-' }}</td><td><x-crm.status-badge variant="neutral">{{ $lead->current_status?->label() ?? '-' }}</x-crm.status-badge></td><td>{{ $lead->latest_activity_at?->format('d/m/Y H:i') ?? '-' }}</td><td><x-crm.button variant="text" size="sm" :href="route('sales-leads.show', $lead)">Detail</x-crm.button></td></tr>@empty<tr><td colspan="10"><x-crm.empty-state title="Belum ada lead" description="Lead sesuai filter akan tampil di sini." /></td></tr>@endforelse</tbody></table></div>
            <x-crm.pagination :collection="$leads" :show-per-page="false" strip-query-key="agenda_page" />
        </x-crm.section>
    @else
        <x-crm.section id="admin-monitoring-agenda" title="Agenda Cabang" description="Agenda Sales dalam cakupan cabang Anda.">
            <div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Sales</th><th>Koordinator</th><th>Proyek</th><th>Kategori</th><th>Agenda</th><th>Lokasi</th><th>Hasil</th><th>Status</th></tr></thead><tbody>@forelse($agendas as $agenda)@php([$agendaStatusLabel, $agendaStatusVariant] = $agendaStatuses[$agenda->status] ?? [ucfirst(str_replace('_', ' ', $agenda->status)), 'neutral'])<tr><td>{{ $agenda->scheduled_date->format('d/m/Y') }}</td><td>{{ $agenda->owner?->name ?: '-' }}</td><td>{{ collect($coordinatorNamesBySalesId[$agenda->owner_user_id] ?? [])->join(', ') ?: '-' }}</td><td>{{ $agenda->salesProject?->project_name ?: '-' }}</td><td>{{ $agenda->sales_activity_category ?: '-' }}</td><td>{{ $agenda->title }}</td><td>{{ $agenda->location ?: '-' }}</td><td>{{ $agenda->activity_result ?: '-' }}</td><td><x-crm.status-badge :variant="$agendaStatusVariant">{{ $agendaStatusLabel }}</x-crm.status-badge></td></tr>@empty<tr><td colspan="9"><x-crm.empty-state title="Belum ada agenda" description="Agenda sesuai filter akan tampil di sini." /></td></tr>@endforelse</tbody></table></div>
            <x-crm.pagination :collection="$agendas" :show-per-page="false" strip-query-key="lead_page" />
        </x-crm.section>
    @endif

    <x-crm.modal name="admin-lead-detail" title="Detail Lead" description="Informasi read only Lead cabang.">
        <template x-if="selectedLead"><dl class="grid gap-4 sm:grid-cols-2"><div><dt class="text-xs font-bold uppercase">Nama Konsumen</dt><dd class="mt-1" x-text="selectedLead.customer_name"></dd></div><div><dt class="text-xs font-bold uppercase">No. Telepon</dt><dd class="mt-1" x-text="selectedLead.phone"></dd></div><div class="sm:col-span-2"><dt class="text-xs font-bold uppercase">Catatan</dt><dd class="mt-1 whitespace-pre-wrap" x-text="selectedLead.notes"></dd></div><div><dt class="text-xs font-bold uppercase">Status Lifecycle</dt><dd class="mt-1" x-text="selectedLead.status"></dd></div><div><dt class="text-xs font-bold uppercase">Aktivitas Terbaru</dt><dd class="mt-1" x-text="selectedLead.latest_activity"></dd></div><div class="sm:col-span-2"><dt class="text-xs font-bold uppercase">Ringkasan Lifecycle</dt><dd class="mt-1 whitespace-pre-wrap" x-text="selectedLead.lifecycle"></dd></div></dl></template>
    </x-crm.modal>
</div>
@endsection
