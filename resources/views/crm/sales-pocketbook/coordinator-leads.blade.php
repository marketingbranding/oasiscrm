@extends('layouts.crm')

@section('title', 'Lead Tim Sales - Oasis CRM')

@section('content')
@php
    $initialSalesId = (string) old('sales_user_id', $salesUsers->first()?->id ?? '');
    $initialProjects = $projectsBySales->get($initialSalesId, collect());
    $initialProjectId = (string) old('project_id', $initialProjects->first()['id'] ?? '');
@endphp
<div class="space-y-4" x-data="{
    sales: @js($initialSalesId),
    project: @js($initialProjectId),
    branch: @js((string) old('branch_id', $initialProjects->first()['branch_id'] ?? '')),
    projectsBySales: @js($projectsBySales),
    get projects() { return this.projectsBySales[this.sales] || [] },
    salesChanged() { this.project = this.projects[0]?.id || ''; this.projectChanged() },
    projectChanged() { this.branch = this.projects.find(item => item.id === this.project)?.branch_id || '' },
}">
    <x-crm.page-header variant="canonical" eyebrow="Workspace Koordinator" title="Lead Tim Sales" description="Input dan pantau lead anggota aktif tim Sales.">
        <x-slot:actions>
            <x-crm.button variant="primary" accent="sales" href="#coordinator-lead-input">INPUT LEAD</x-crm.button>
            <x-crm.button variant="secondary" :href="route('coordinator-leads.export')">EXPORT XLSX</x-crm.button>
            @if($canSync)
                <form method="POST" action="{{ route('coordinator-leads.sync') }}">@csrf<x-crm.button type="submit" variant="secondary">SYNC KE SPREADSHEET</x-crm.button></form>
            @endif
        </x-slot:actions>
    </x-crm.page-header>

    @if($errors->any())
        <x-crm.alert variant="error" title="Data belum tersimpan">{{ $errors->first() }}</x-crm.alert>
    @endif

    <section aria-label="Ringkasan status sinkronisasi" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach([
            ['BELUM SYNC', (int) ($syncCounters->pending_create ?? 0), 'pending'],
            ['TERSYNC', (int) ($syncCounters->synced ?? 0), 'success'],
            ['PERLU SYNC ULANG', (int) ($syncCounters->pending_update ?? 0), 'warning'],
            ['SYNC GAGAL', (int) ($syncCounters->sync_failed ?? 0), 'error'],
        ] as [$label, $count, $variant])
            <x-crm.card><div class="flex items-center justify-between gap-2"><x-crm.status-badge :variant="$variant">{{ $label }}</x-crm.status-badge><strong class="text-xl">{{ $count }}</strong></div></x-crm.card>
        @endforeach
    </section>

    @if($salesUsers->isEmpty())
        <x-crm.empty-state title="Belum ada anggota Sales aktif" description="Lead tim tersedia setelah penugasan Sales koordinator aktif." />
    @else
        <section id="coordinator-lead-input" class="border-2 border-black bg-white">
            <div class="bg-black px-4 py-2 text-xs font-bold uppercase text-[#fcc20f]">Input Lead Tim</div>
            <form method="POST" action="{{ route('sales-leads.store') }}" class="grid grid-cols-1 gap-3 p-4 md:grid-cols-2 xl:grid-cols-4">
                @csrf
                <input type="hidden" name="operation_uuid" value="{{ old('operation_uuid', (string) Illuminate\Support\Str::uuid()) }}">
                <input type="hidden" name="branch_id" x-model="branch">
                <x-crm.field label="Sales PIC" for="coordinator-lead-sales" required :error="$errors->first('sales_user_id')"><select id="coordinator-lead-sales" class="sales-input" name="sales_user_id" x-model="sales" @change="salesChanged()" required>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}">{{ $sales->name }}</option>@endforeach</select></x-crm.field>
                <x-crm.field label="Proyek" for="coordinator-lead-project" required :error="$errors->first('project_id')"><select id="coordinator-lead-project" class="sales-input" name="project_id" x-model="project" @change="projectChanged()" required><option value="">Pilih proyek</option><template x-for="item in projects" :key="item.id"><option :value="item.id" x-text="item.name"></option></template></select></x-crm.field>
                <div class="crm-field"><span class="crm-field-label">Cabang</span><div class="crm-field-control border border-gray-400 bg-gray-100 px-3 py-2 text-sm" x-text="projects.find(item => item.id === project)?.branch_name || 'Dipilih otomatis dari proyek'"></div></div>
                <x-crm.field label="Tanggal Lead" for="coordinator-lead-date" required :error="$errors->first('lead_date')"><x-crm.date-field id="coordinator-lead-date" name="lead_date" :value="old('lead_date', now()->toDateString())" required /></x-crm.field>
                <x-crm.field label="Nama Calon Konsumen" for="coordinator-lead-name" required :error="$errors->first('customer_name')"><input id="coordinator-lead-name" class="sales-input" name="customer_name" value="{{ old('customer_name') }}" required></x-crm.field>
                <x-crm.field label="No. WhatsApp / Telepon" for="coordinator-lead-phone" :error="$errors->first('phone')"><input id="coordinator-lead-phone" class="sales-input" name="phone" value="{{ old('phone') }}"></x-crm.field>
                <x-crm.field label="Sumber Lead" for="coordinator-lead-source" required :error="$errors->first('source')"><input id="coordinator-lead-source" class="sales-input" name="source" value="{{ old('source') }}" required></x-crm.field>
                <x-crm.field label="Kanal Masuk" for="coordinator-lead-platform" required :error="$errors->first('platform')"><input id="coordinator-lead-platform" class="sales-input" name="platform" value="{{ old('platform') }}" required></x-crm.field>
                <x-crm.field label="Aktivitas Lead" for="coordinator-lead-campaign" required :error="$errors->first('campaign_name')"><input id="coordinator-lead-campaign" class="sales-input" name="campaign_name" value="{{ old('campaign_name') }}" required></x-crm.field>
                <x-crm.field label="ID Promo" for="coordinator-lead-promo" :error="$errors->first('id_promo')"><input id="coordinator-lead-promo" class="sales-input" name="id_promo" value="{{ old('id_promo') }}"></x-crm.field>
                <x-crm.field label="Status Lead" for="coordinator-lead-status" required :error="$errors->first('current_status')"><select id="coordinator-lead-status" class="sales-input" name="current_status" required><option value="no_response">No Respon</option><option value="discussion" @selected(old('current_status') === 'discussion')>Diskusi</option><option value="site_visit" @selected(old('current_status') === 'site_visit')>Cek Lokasi</option></select></x-crm.field>
                <x-crm.field label="Catatan" for="coordinator-lead-notes" :error="$errors->first('notes')" class="md:col-span-2"><textarea id="coordinator-lead-notes" class="sales-input" name="notes" rows="2">{{ old('notes') }}</textarea></x-crm.field>
                <div class="md:col-span-2 xl:col-span-4"><x-crm.button type="submit" variant="primary" accent="sales" x-bind:disabled="!branch || !project">Simpan Lead</x-crm.button></div>
            </form>
        </section>
    @endif

    <section class="border-2 border-black bg-white">
        <header class="flex items-center justify-between border-b-2 border-black bg-black px-4 py-2 text-white"><h2 class="text-sm font-bold uppercase">Lead Tim</h2><span>{{ $leads->total() }} lead</span></header>
        <div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Tanggal</th><th>Konsumen</th><th>Sales PIC</th><th>Cabang</th><th>Proyek</th><th>Status Lead</th><th>Status Sync</th><th>Aksi</th></tr></thead><tbody>
            @forelse($leads as $lead)
                @php([$syncLabel, $syncVariant] = match($lead->sync_status) { 'synced' => ['TERSYNC', 'success'], 'pending_update' => ['PERLU SYNC ULANG', 'warning'], 'sync_failed' => ['SYNC GAGAL', 'error'], default => ['BELUM SYNC', 'pending'] })
                <tr><td>{{ $lead->lead_date->format('d/m/Y') }}</td><td>{{ $lead->customer_name }}</td><td>{{ $lead->sales?->name }}</td><td>{{ $lead->branch?->name }}</td><td>{{ $lead->project?->project_name }}</td><td>{{ $lead->current_status->label() }}</td><td><x-crm.status-badge :variant="$syncVariant">{{ $syncLabel }}</x-crm.status-badge></td><td><a class="font-bold text-[#0000ee] underline" href="{{ route('sales-leads.edit', $lead) }}">Edit</a></td></tr>
            @empty
                <tr><td colspan="8"><x-crm.empty-state title="Belum ada lead tim" description="Lead anggota aktif tim akan muncul di sini." /></td></tr>
            @endforelse
        </tbody></table></div>
        <x-crm.pagination :collection="$leads" :show-per-page="false" />
    </section>
</div>
@endsection
