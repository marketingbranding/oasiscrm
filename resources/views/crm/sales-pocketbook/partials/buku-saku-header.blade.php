<header class="sales-reference-header" aria-labelledby="sales-reference-title">
    <div class="sales-reference-brandline">
        <span class="sales-reference-brand">OASIS CRM</span>
        <details class="sales-reference-menu" @keydown.escape.prevent.stop="$el.removeAttribute('open')">
            <summary aria-label="Buka opsi Buku Saku">{{ str($project?->project_name ?? Auth::user()->name)->substr(0, 1)->upper() }}</summary>
            <div class="sales-reference-menu-panel">
                <form method="POST" action="{{ route('sales-pocketbook.onboarding.replay') }}">
                    @csrf
                    <button type="submit">Buka bantuan tur</button>
                </form>
                <button type="button" class="sales-tear-sound-toggle" data-sales-tear-sound aria-pressed="true">Suara sobek: aktif</button>
                @if($canExport)<a href="{{ route('sales-agendas.export') }}">Export XLSX</a>@endif
            </div>
        </details>
    </div>
    <div class="sales-reference-heading">
        <h1 id="sales-reference-title">Buku Saku Saya</h1>
        <p>{{ Auth::user()->name }} · {{ $project?->branch?->name ?? 'Cabang belum tersedia' }} · {{ $project?->project_name ?? 'Proyek utama belum tersedia' }}</p>
    </div>
</header>

<nav class="sales-pocketbook-tabs crm-horizontal-tabs" aria-label="Workspace pribadi Sales">
    <a href="{{ route('sales-agendas.index') }}" class="sales-pocketbook-tab {{ $tab === 'agenda' ? 'active' : '' }}" @if($tab === 'agenda') aria-current="page" @endif data-tour-target="agenda-tab">Agenda</a>
    <a href="{{ route('sales-agendas.index', ['tab' => 'leads']) }}" class="sales-pocketbook-tab {{ $tab === 'leads' ? 'active' : '' }}" @if($tab === 'leads') aria-current="page" @endif data-tour-target="lead-tab">Lead Saya</a>
</nav>
