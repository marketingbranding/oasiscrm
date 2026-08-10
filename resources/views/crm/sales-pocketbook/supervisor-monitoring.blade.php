@extends('layouts.crm')

@section('title', 'Monitoring Tim - Buku Saku Sales')

@section('content')
@php
    $query = fn (array $changes = []) => array_filter(array_merge(request()->only(['period', 'date_from', 'date_to', 'coordinator_id', 'sales_id']), $changes), fn ($value) => filled($value));
    $syncLabels = ['pending_create' => 'Belum Sync', 'synced' => 'Tersinkron', 'pending_update' => 'Perlu Sync Ulang', 'sync_failed' => 'Sync Gagal'];
@endphp
<div class="space-y-5">
    <x-crm.page-header variant="canonical" eyebrow="BUKU SAKU SALES" title="Monitoring Tim" description="Pantau Koordinator, Sales, agenda, dan lead yang perlu ditindaklanjuti.">
        <x-slot:meta><x-crm.status-badge variant="inactive">READONLY SPV</x-crm.status-badge></x-slot:meta>
        <x-slot:actions>
            <x-crm.button variant="secondary" :href="route('sales-pocketbook.supervisor-monitoring.agenda-export', $query())">Export Aktivitas Sales</x-crm.button>
            <x-crm.button variant="secondary" :href="route('sales-pocketbook.supervisor-monitoring.lead-export', $query())">Export Lead Tim</x-crm.button>
        </x-slot:actions>
    </x-crm.page-header>

    <div class="sales-pocketbook-scope" aria-label="Konteks monitoring Supervisor">
        <div><span>SPV</span><strong>{{ Auth::user()->name }}</strong></div>
        <div><span>Periode</span><strong>{{ $period['from']->format('d/m/Y') }} - {{ $period['to']->format('d/m/Y') }}</strong></div>
    </div>

    <x-crm.toolbar aria-label="Filter monitoring tim">
        <div class="flex flex-wrap gap-2">
            @foreach (['today' => 'Hari Ini', 'week' => 'Minggu Ini', 'month' => 'Bulan Ini'] as $value => $label)
                <x-crm.button size="sm" :variant="$filters['period'] === $value ? 'primary' : 'secondary'" :href="route('sales-pocketbook.index', $query(['period' => $value, 'date_from' => null, 'date_to' => null]))">{{ $label }}</x-crm.button>
            @endforeach
        </div>
        <form method="GET" class="grid flex-1 gap-2 md:grid-cols-4">
            <input type="hidden" name="period" value="custom">
            <x-crm.field label="Dari" for="spv-date-from"><x-crm.date-field id="spv-date-from" name="date_from" :value="$filters['date_from']" required /></x-crm.field>
            <x-crm.field label="Sampai" for="spv-date-to"><x-crm.date-field id="spv-date-to" name="date_to" :value="$filters['date_to']" required /></x-crm.field>
            <x-crm.field label="Koordinator" for="spv-coordinator"><select id="spv-coordinator" name="coordinator_id" class="sales-input"><option value="">Semua Koordinator</option>@foreach ($coordinators as $coordinator)<option value="{{ $coordinator->id }}" @selected($filters['coordinator_id'] === $coordinator->id)>{{ $coordinator->name }}</option>@endforeach</select></x-crm.field>
            <x-crm.field label="Sales" for="spv-sales"><select id="spv-sales" name="sales_id" class="sales-input"><option value="">Semua Sales</option>@foreach ($salesUsers as $sales)<option value="{{ $sales->id }}" @selected($filters['sales_id'] === $sales->id)>{{ $sales->name }}</option>@endforeach</select></x-crm.field>
            <div class="md:col-span-4"><x-crm.button type="submit" variant="primary" accent="sales">Terapkan Filter</x-crm.button></div>
        </form>
    </x-crm.toolbar>

    <section aria-label="Ringkasan monitoring" class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
        @foreach (['coordinator_count' => 'Koordinator', 'sales_count' => 'Sales Aktif', 'agenda_count' => 'Agenda', 'agenda_done' => 'Agenda Selesai', 'lead_count' => 'Lead Masuk', 'pending_create' => 'Belum Sync', 'pending_update' => 'Perlu Sync Ulang', 'sync_failed' => 'Sync Gagal'] as $key => $label)
            <x-crm.card padding="sm"><div class="text-[10px] font-bold uppercase">{{ $label }}</div><div class="mt-1 text-2xl font-black">{{ $kpi[$key] }}</div></x-crm.card>
        @endforeach
    </section>

    @if($coordinators->isEmpty())
        <x-crm.empty-state title="Belum ada Koordinator yang ditugaskan ke Anda." />
    @else
        <x-crm.section id="supervisor-attention" title="Perlu Perhatian" description="Orang dan data yang perlu ditindaklanjuti pada periode terpilih.">
            <div class="grid gap-4 md:grid-cols-3">
                <div><h3 class="text-xs font-bold uppercase">{{ $attention['without_agenda']->count() }} Sales belum membuat agenda</h3><p class="text-xs text-gray-600">{{ $filters['period'] === 'today' ? 'Belum ada agenda hari ini' : 'Belum ada agenda pada periode ini' }}</p>@foreach($attention['without_agenda'] as $row)<a class="mt-2 block font-bold text-[#0000ee] underline" href="{{ route('sales-pocketbook.index', $query(['sales_id' => $row->id])) }}">{{ $row->name }}</a>@endforeach</div>
                <div><h3 class="text-xs font-bold uppercase">Lead perlu sinkron</h3>@foreach($attention['pending'] as $row)<a class="mt-2 block text-[#0000ee] underline" href="{{ route('sales-pocketbook.index', $query(['sales_id' => $row->id])) }}">{{ $row->name }}: {{ $row->pending_create }} belum sync, {{ $row->pending_update }} perlu ulang, {{ $row->sync_failed }} gagal</a>@endforeach @if($attention['pending']->isEmpty())<p class="mt-2 text-sm text-gray-600">Tidak ada lead tertunda.</p>@endif</div>
                <div><h3 class="text-xs font-bold uppercase">Agenda belum hasil</h3>@foreach($attention['missing_result'] as $row)<a class="mt-2 block text-[#0000ee] underline" href="{{ route('sales-pocketbook.index', $query(['sales_id' => $row->id])) }}">{{ $row->name }}: {{ $row->missing_result }}</a>@endforeach @if($attention['missing_result']->isEmpty())<p class="mt-2 text-sm text-gray-600">Semua hasil agenda sudah terisi.</p>@endif</div>
            </div>
        </x-crm.section>

        <x-crm.section id="coordinator-performance" title="Kinerja Koordinator">
            <div class="grid gap-3 lg:grid-cols-2">
                @foreach($coordinatorRows as $row)
                    <a href="{{ route('sales-pocketbook.index', $query(['coordinator_id' => $row->id, 'sales_id' => null])) }}" class="block border-2 border-black bg-white p-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--oasis-focus)]">
                        <strong class="block">{{ $row->name }}</strong><span class="mt-1 block text-sm">{{ $row->sales_count }} Sales · {{ $row->lead_count }} Lead</span><span class="block text-xs">{{ $row->pending_create }} belum sync · {{ $row->pending_update }} perlu ulang · {{ $row->sync_failed }} gagal</span><span class="block text-xs text-gray-600">Terakhir input: {{ $row->latest_lead ? \Carbon\Carbon::parse($row->latest_lead)->format('d/m/Y H:i') : '-' }}</span>
                    </a>
                @endforeach
            </div>
            @if($coordinatorRows->contains(fn ($row) => $row->sales_count === 0))<p class="mt-3 text-sm">Koordinator belum memiliki Sales aktif.</p>@endif
        </x-crm.section>

        <x-crm.section id="sales-activity" title="Aktivitas Sales">
            @if($salesRows->isEmpty())
                <x-crm.empty-state title="Koordinator belum memiliki Sales aktif." />
            @else
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach($salesRows as $row)
                        <a href="{{ route('sales-pocketbook.index', $query(['sales_id' => $row->id])) }}" class="block border-2 border-black bg-white p-3">
                            <strong>{{ $row->name }}</strong><span class="block text-xs">{{ implode('; ', $row->coordinator_names) }} · {{ $row->branch_name ?: '-' }} · {{ $row->project_name ?: '-' }}</span><span class="mt-2 block text-sm">{{ $row->agenda_count }} Agenda · {{ $row->agenda_done }} Selesai · {{ $row->lead_count }} Lead</span>@if($row->agenda_count === 0)<x-crm.status-badge variant="warning">Belum Ada Agenda</x-crm.status-badge>@endif
                        </a>
                    @endforeach
                </div>
            @endif
        </x-crm.section>
    @endif

    @if($filters['sales_id'])
        @php($selectedSales = $salesRows->first())
        <x-crm.section id="sales-detail" :title="$selectedSales?->name ?? 'Detail Sales'" :description="'Koordinator: '.implode('; ', $selectedSales?->coordinator_names ?? []).' · Cabang: '.($selectedSales?->branch_name ?? '-').' · Proyek: '.($selectedSales?->project_name ?? '-')">
            <h3 class="mb-2 text-xs font-bold uppercase">Agenda</h3>
            <div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Agenda</th><th>Lokasi</th><th>Hasil</th><th>Status</th></tr></thead><tbody>@forelse($agendas as $agenda)<tr><td>{{ $agenda->scheduled_date->format('d/m/Y') }}</td><td>{{ $agenda->title }}</td><td>{{ $agenda->location ?: '-' }}</td><td>{{ $agenda->activity_result ?: '-' }}</td><td>{{ $agenda->status === 'done' ? 'Selesai' : 'Belum Selesai' }}</td></tr>@empty<tr><td colspan="5">Belum ada agenda pada periode ini.</td></tr>@endforelse</tbody></table></div>{{ $agendas->links() }}
            <h3 class="mb-2 mt-5 text-xs font-bold uppercase">Lead</h3>
            <div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Konsumen</th><th>Sumber</th><th>Kanal</th><th>Aktivitas</th><th>Status Lead</th><th>Status Sync</th></tr></thead><tbody>@forelse($leads as $lead)<tr><td>{{ $lead->lead_date->format('d/m/Y') }}</td><td>{{ $lead->customer_name }}</td><td>{{ $lead->effective_source }}</td><td>{{ $lead->platform }}</td><td>{{ $lead->campaign_name }}</td><td>{{ $lead->current_status?->label() }}</td><td>{{ $syncLabels[$lead->sync_status] ?? 'Belum Sync' }}</td></tr>@empty<tr><td colspan="7">Belum ada lead pada periode ini.</td></tr>@endforelse</tbody></table></div>{{ $leads->links() }}
        </x-crm.section>
    @endif
</div>
@endsection
