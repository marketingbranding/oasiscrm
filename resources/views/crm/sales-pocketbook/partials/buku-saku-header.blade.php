<x-crm.page-header variant="canonical" eyebrow="Workspace Pribadi" title="Buku Saku Saya">
    <x-slot:actions>
        <form method="POST" action="{{ route('sales-pocketbook.onboarding.replay') }}">
            @csrf
            <button type="submit" class="sales-pocketbook-help-link">Lihat panduan Buku Saku</button>
        </form>
        @if($canExport)<x-crm.button variant="secondary" :href="route('sales-agendas.export')">Export XLSX</x-crm.button>@endif
    </x-slot:actions>
</x-crm.page-header>

<nav class="sales-pocketbook-tabs crm-horizontal-tabs" aria-label="Workspace pribadi Sales">
    <a href="{{ route('sales-agendas.index') }}" class="sales-pocketbook-tab {{ $tab === 'agenda' ? 'active' : '' }}" @if($tab === 'agenda') aria-current="page" @endif>Agenda</a>
    <a href="{{ route('sales-agendas.index', ['tab' => 'leads']) }}" class="sales-pocketbook-tab {{ $tab === 'leads' ? 'active' : '' }}" @if($tab === 'leads') aria-current="page" @endif>Lead Saya</a>
</nav>

<div class="sales-pocketbook-context-strip sales-pocketbook-scope" aria-label="Konteks workspace aktif">
    <div><span>Sales</span><strong>{{ Auth::user()->name }}</strong></div>
    <div><span>Cabang</span><strong>{{ $project?->branch?->name ?? 'Belum tersedia' }}</strong></div>
    <div><span>Proyek</span><strong>{{ $project?->project_name ?? 'Belum tersedia' }}</strong></div>
</div>
