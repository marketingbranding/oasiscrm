@extends('layouts.crm')

@section('title', 'Lead Tim Sales - Oasis CRM')

@section('content')
@php
    $initialSalesId = (string) old('sales_user_id', $salesUsers->first()?->id ?? '');
    $initialProjects = $projectsBySales->get($initialSalesId, collect());
    $initialProjectId = (string) old('project_id', $initialProjects->count() === 1 ? $initialProjects->first()['id'] : '');
    $agendaStatuses = [
        'planned' => ['Direncanakan', 'pending'],
        'confirmed' => ['Dikonfirmasi', 'info'],
        'done' => ['Selesai', 'success'],
        'cancelled' => ['Dibatalkan', 'inactive'],
        'rescheduled' => ['Dijadwalkan Ulang', 'warning'],
    ];
@endphp
<div class="space-y-4" x-data="{ sales: @js($initialSalesId), project: @js($initialProjectId), branch: @js((string) old('branch_id', $initialProjects->count() === 1 ? $initialProjects->first()['branch_id'] : '')), projectsBySales: @js($projectsBySales), get projects() { return this.projectsBySales[this.sales] || [] }, salesChanged() { this.project = this.projects.length === 1 ? this.projects[0].id : ''; this.projectChanged() }, projectChanged() { this.branch = this.projects.find(item => item.id === this.project)?.branch_id || '' } }">
    <x-crm.page-header variant="canonical" eyebrow="Koordinator Sales" title="Lead Tim Sales" description="Input dan pantau lead anggota aktif tim Sales."><x-slot:actions><x-crm.button variant="primary" accent="sales" href="#coordinator-lead-input">INPUT LEAD</x-crm.button><x-crm.button variant="secondary" :href="route('coordinator-leads.export', $filters)">EXPORT LEAD</x-crm.button>@if($canSync)<form method="POST" action="{{ route('coordinator-leads.sync') }}">@csrf<x-crm.button type="submit" variant="secondary">SYNC KE SPREADSHEET</x-crm.button></form>@endif</x-slot:actions></x-crm.page-header>

    @if($errors->any())<x-crm.alert variant="error" title="Data belum tersimpan">{{ $errors->first() }}</x-crm.alert>@endif

    <x-crm.section id="coordinator-team" title="Tim Sales Saya" :description="$salesUsers->isEmpty() ? 'Belum ada Sales aktif dalam Tim Sales Saya.' : $salesUsers->count().' Sales: '.$salesUsers->pluck('name')->join(', ')" />

    <x-crm.section id="coordinator-period" title="Ringkasan Periode" :description="$period['from']->format('d/m/Y').' - '.$period['to']->format('d/m/Y')">
        <x-crm.toolbar label="Filter periode koordinator">
        <form method="GET" class="grid w-full gap-3 md:grid-cols-4">
            <div class="flex flex-wrap gap-2 md:col-span-4">@foreach(['today' => 'Hari Ini', 'week' => 'Minggu Ini', 'month' => 'Bulan Ini', 'custom' => 'Kustom'] as $value => $label)<button class="border-2 border-black px-3 py-2 text-xs font-bold {{ $filters['period'] === $value ? 'bg-[#fcc20f]' : 'bg-white' }}" name="period" value="{{ $value }}">{{ $label }}</button>@endforeach</div>
            <x-crm.field label="Dari" for="monitor-date-from"><x-crm.date-field id="monitor-date-from" name="date_from" :value="$filters['date_from']" /></x-crm.field>
            <x-crm.field label="Sampai" for="monitor-date-to"><x-crm.date-field id="monitor-date-to" name="date_to" :value="$filters['date_to']" /></x-crm.field>
            <x-crm.field label="Sales" for="monitor-sales"><select id="monitor-sales" class="sales-input" name="sales_id"><option value="">Semua Sales</option>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" @selected((string) $filters['sales_id'] === (string) $sales->id)>{{ $sales->name }}</option>@endforeach</select></x-crm.field>
            <div class="self-end"><x-crm.button type="submit" variant="secondary">Terapkan</x-crm.button></div>
        </form>
        </x-crm.toolbar>
        @if(array_sum($kpi) === 0)
            <div class="p-4"><x-crm.empty-state title="Belum ada aktivitas pada periode ini." description="Metrik akan terisi setelah lead atau agenda selesai tercatat dalam scope dan periode yang dipilih." /></div>
        @else
            <div class="grid grid-cols-2 gap-3 p-4 lg:grid-cols-4">@foreach(['Lead Baru' => $kpi['lead_new'], 'Tatap Muka' => $kpi['face_to_face'], 'Cek Lokasi' => $kpi['site_visit'], 'UTJ' => $kpi['utj']] as $label => $count)<x-crm.card><div class="text-xs font-bold uppercase">{{ $label }}</div><strong class="text-xl">{{ $count }}</strong></x-crm.card>@endforeach</div>
        @endif
    </x-crm.section>

    <x-crm.section id="coordinator-performance" title="Performa Sales"><div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Sales</th><th>Cabang</th><th>Proyek</th><th>Lead Baru</th><th>Tatap Muka</th><th>Cek Lokasi</th><th>UTJ</th></tr></thead><tbody>@forelse($salesRows as $row)<tr><td>{{ $row->name }}</td><td>{{ $row->branch_name ?: '-' }}</td><td>{{ $row->project_name ?: '-' }}</td><td>{{ $row->lead_new }}</td><td>{{ $row->face_to_face }}</td><td>{{ $row->site_visit }}</td><td>{{ $row->utj }}</td></tr>@empty<tr><td colspan="7">Belum ada Sales pada cakupan ini.</td></tr>@endforelse</tbody></table></div></x-crm.section>

    @if($salesUsers->isNotEmpty())
    <x-crm.section id="coordinator-lead-input" title="Input Lead Tim">
        <form method="POST" action="{{ route('sales-leads.store') }}" class="grid grid-cols-1 gap-3 p-4 md:grid-cols-2 xl:grid-cols-4">@csrf
            <input type="hidden" name="operation_uuid" value="{{ old('operation_uuid', (string) Illuminate\Support\Str::uuid()) }}"><input type="hidden" name="branch_id" x-model="branch">
            <x-crm.field label="Sales PIC" for="coordinator-lead-sales" required><select id="coordinator-lead-sales" class="sales-input" name="sales_user_id" x-model="sales" @change="salesChanged()" required><option value="">Pilih Sales</option>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}">{{ $sales->name }}</option>@endforeach</select></x-crm.field>
            <x-crm.field label="Proyek" for="coordinator-lead-project" required><select id="coordinator-lead-project" class="sales-input" name="project_id" x-model="project" @change="projectChanged()" required><option value="">Pilih proyek</option><template x-for="item in projects" :key="item.id"><option :value="item.id" x-text="item.name"></option></template></select><p x-show="projects.length === 0" class="mt-1 text-xs text-red-700">Sales belum memiliki proyek aktif. Lead tidak dapat disimpan.</p></x-crm.field>
            <div class="crm-field"><span class="crm-field-label">Cabang</span><div class="crm-field-control border border-gray-400 bg-gray-100 px-3 py-2 text-sm" x-text="projects.find(item => item.id === project)?.branch_name || 'Dipilih otomatis dari proyek'"></div></div>
            <x-crm.field label="Tanggal Lead" for="coordinator-lead-date" required><x-crm.date-field id="coordinator-lead-date" name="lead_date" :value="old('lead_date', now()->toDateString())" required /></x-crm.field>
            <x-crm.field label="Nama Calon Konsumen" for="coordinator-lead-name" required><input id="coordinator-lead-name" class="sales-input" name="customer_name" value="{{ old('customer_name') }}" placeholder="Masukkan nama calon konsumen" required></x-crm.field>
            <x-crm.field label="No. WhatsApp / Telepon" for="coordinator-lead-phone"><input id="coordinator-lead-phone" class="sales-input" name="phone" value="{{ old('phone') }}" placeholder="Contoh: 081234567890"></x-crm.field>
            @foreach([['Sumber Lead','source',$sources,'Pilih sumber lead'],['Kanal Masuk','platform',$channels,'Pilih kanal masuk'],['Aktivitas Lead','campaign_name',$activities,'Pilih aktivitas lead']] as [$label,$name,$options,$placeholder])<x-crm.field :label="$label" :for="'coordinator-lead-'.$name" required><select :id="'coordinator-lead-'.$name" class="sales-input" :name="$name" required><option value="">{{ $placeholder }}</option>@foreach($options as $option)<option value="{{ $option }}" @selected(old($name) === $option)>{{ $option }}</option>@endforeach</select></x-crm.field>@endforeach
            <x-crm.field label="Nama Promo" for="coordinator-lead-promo"><select id="coordinator-lead-promo" class="sales-input" name="promo_name"><option value="">Pilih promo</option>@foreach($promos as $promo)<option value="{{ $promo }}" @selected(old('promo_name') === $promo)>{{ $promo }}</option>@endforeach</select></x-crm.field>
            <x-crm.field label="Status Lead" for="coordinator-lead-status" required><select id="coordinator-lead-status" class="sales-input" name="current_status" required><option value="">Pilih status lead</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(old('current_status', 'no_response') === $status->value)>{{ $status->label() }}</option>@endforeach</select></x-crm.field>
            <x-crm.field label="Catatan" for="coordinator-lead-notes" class="md:col-span-2"><textarea id="coordinator-lead-notes" class="sales-input" name="notes" rows="2" placeholder="Tambahkan catatan lead">{{ old('notes') }}</textarea></x-crm.field>
            <div class="md:col-span-2 xl:col-span-4"><x-crm.button type="submit" variant="primary" accent="sales" x-bind:disabled="!branch || !project">Simpan Lead</x-crm.button></div>
        </form>
    </x-crm.section>
    @endif

    <x-crm.section id="coordinator-team-agenda" title="Agenda Sales Tim" description="Agenda Sales aktif dalam tim dan periode terpilih.">
        <div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Sales PIC</th><th>Cabang</th><th>Proyek</th><th>Kategori</th><th>Agenda</th><th>Lokasi</th><th>Hasil</th><th>Status</th></tr></thead><tbody>@forelse($agendas as $agenda)@php([$agendaStatusLabel, $agendaStatusVariant] = $agendaStatuses[$agenda->status] ?? [ucfirst(str_replace('_', ' ', $agenda->status)), 'neutral'])<tr><td>{{ $agenda->scheduled_date->format('d/m/Y') }}</td><td>{{ $agenda->owner?->name }}</td><td>{{ $agenda->branch?->name }}</td><td>{{ $agenda->salesProject?->project_name }}</td><td>{{ $agenda->sales_activity_category ?: '-' }}</td><td>{{ $agenda->title }}</td><td>{{ $agenda->location ?: '-' }}</td><td>{{ $agenda->activity_result ?: '-' }}</td><td><x-crm.status-badge :variant="$agendaStatusVariant">{{ $agendaStatusLabel }}</x-crm.status-badge></td></tr>@empty<tr><td colspan="9"><x-crm.empty-state title="Belum ada agenda tim" description="Agenda Sales pada periode terpilih akan tampil di sini." /></td></tr>@endforelse</tbody></table></div>
        <x-crm.pagination :collection="$agendas" :show-per-page="false" />
    </x-crm.section>

    <x-crm.section id="coordinator-team-leads" title="Lead Tim" description="Lead yang dicatat untuk Sales aktif dalam tim Anda.">
        <x-slot:actions><x-crm.status-badge variant="neutral">{{ $leads->total() }} Lead</x-crm.status-badge></x-slot:actions>
        <div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Konsumen</th><th>Sales PIC</th><th>Cabang</th><th>Proyek</th><th>Promo</th><th>Status Lead</th><th>Aksi</th></tr></thead><tbody>@forelse($leads as $lead)<tr><td>{{ $lead->lead_date->format('d/m/Y') }}</td><td>{{ $lead->customer_name }}</td><td>{{ $lead->sales?->name }}</td><td>{{ $lead->branch?->name }}</td><td>{{ $lead->project?->project_name }}</td><td>{{ $lead->id_promo ?: '-' }}</td><td>{{ $lead->current_status->label() }}</td><td><x-crm.button variant="text" size="sm" :href="route('sales-leads.edit', $lead)">Edit</x-crm.button></td></tr>@empty<tr><td colspan="8"><x-crm.empty-state title="Belum ada lead tim" description="Lead anggota aktif tim pada periode ini akan muncul di sini." /></td></tr>@endforelse</tbody></table></div>
        <x-crm.pagination :collection="$leads" :show-per-page="false" />
    </x-crm.section>
</div>
@endsection
