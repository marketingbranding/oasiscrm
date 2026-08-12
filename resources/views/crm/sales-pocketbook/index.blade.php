@extends('layouts.crm')

@section('title', 'Buku Saku Sales - Oasis CRM')

@section('content')
@php
    $scopeParams = array_filter(request()->only(['branch_id', 'project_id', 'sales_user_id']), fn ($value) => filled($value));
    $periodParams = array_filter(request()->only(['period_type', 'week', 'date_from', 'date_to']), fn ($value) => filled($value));
    $tabUrl = fn (string $target) => route('sales-pocketbook.index', array_merge(
        ['tab' => $target],
        $scopeParams,
        in_array($target, ['agenda', 'report'], true) ? $periodParams : [],
    ));
    $selectedBranch = $selectedBranchId ? $branches->firstWhere('id', $selectedBranchId) : null;
    $selectedProject = $selectedProjectId ? $projects->firstWhere('id', $selectedProjectId) : null;
    $selectedSales = $monitoring && request()->filled('sales_user_id')
        ? $salesUsers->firstWhere('id', request()->integer('sales_user_id'))
        : null;
    $canExport = Auth::user()->hasPermission('sales_pocketbook.export')
        && Auth::user()->hasScopedPermission('sales_pocketbook', 'export');
    $exportUrl = $canExport ? route('sales-pocketbook.export', array_merge(
        request()->except(['page', 'agenda_page', 'sort', 'direction', 'week', 'date_from', 'date_to', 'period_type']),
        $periodType === 'custom'
            ? ['period_type' => 'custom', 'date_from' => $reportPeriod['start']->toDateString(), 'date_to' => $reportPeriod['end']->toDateString()]
            : ['period_type' => 'week', 'week' => request('week', $reportPeriod['start']->toDateString())],
    )) : null;
    $personalBranchName = $branches->firstWhere('id', Auth::user()->branch_id)?->name ?? 'Belum dipilih';
    $leadResetUrl = route('sales-pocketbook.index', ['tab' => 'leads']);
    $leadFilterUrl = fn (array $remove) => route('sales-pocketbook.index', array_merge(
        request()->except(array_merge($remove, ['page'])),
        ['tab' => 'leads'],
    ));
    $hasLeadPeriodFilter = request()->filled('period_type') || request()->filled('week') || request()->filled('date_from') || request()->filled('date_to');
    $hasLeadFilters = collect(['branch_id', 'project_id', 'sales_user_id', 'lead_source', 'lead_source_id', 'stage', 'report_metric'])
        ->contains(fn (string $key) => request()->filled($key)) || $hasLeadPeriodFilter;
    $activeLeadFilterCount = collect(['branch_id', 'project_id', 'sales_user_id', 'lead_source', 'lead_source_id', 'stage', 'report_metric'])
        ->filter(fn (string $key) => request()->filled($key))->count() + ($hasLeadPeriodFilter ? 1 : 0);
    $selectedLeadSource = $leadSourceFilter;
    $selectedLeadStage = request()->filled('stage') ? (\App\Models\SalesLead::STAGES[request('stage')] ?? null) : null;
    $reportMetricLabels = [
        'lead_new' => 'Lead Baru',
        'contacted' => 'Dihubungi',
        'met' => 'Tatap Muka',
        'surveyed' => 'Survey',
        'utj' => 'UTJ',
        'documents_completed' => 'Berkas Lengkap',
        'akad' => 'Akad',
    ];
    $selectedReportMetric = request()->filled('report_metric') ? ($reportMetricLabels[request('report_metric')] ?? null) : null;
    $agendaResetUrl = route('sales-pocketbook.index', ['tab' => 'agenda']);
    $agendaFilterUrl = fn (array $remove) => route('sales-pocketbook.index', array_merge(
        request()->except(array_merge($remove, ['agenda_page'])),
        ['tab' => 'agenda'],
    ));
    $completedAgendaDrilldown = request()->boolean('report_agenda_completed');
    $missingAgendaResultDrilldown = request()->boolean('report_agenda_missing_result');
    $hasExplicitAgendaPeriod = ! $missingAgendaResultDrilldown
        && (request()->filled('period_type') || request()->filled('week') || request()->filled('date_from') || request()->filled('date_to'));
    $hasAgendaFilters = collect(['branch_id', 'project_id', 'sales_user_id', 'report_agenda_completed', 'report_agenda_missing_result'])
        ->contains(fn (string $key) => request()->filled($key)) || $hasExplicitAgendaPeriod;
    $activeAgendaFilterCount = collect(['branch_id', 'project_id', 'sales_user_id', 'report_agenda_completed', 'report_agenda_missing_result'])
        ->filter(fn (string $key) => request()->filled($key))->count() + ($hasExplicitAgendaPeriod ? 1 : 0);
    $canManageAgenda = Auth::user()->hasScopedPermission('sales_pocketbook', 'manage');
    $canCreateAgenda = $canManageAgenda && (Auth::user()->isSuperadmin()
        || Auth::user()->hasPrimaryRole(['sales', 'manager', 'admin', 'pusat']));
    $leadOptionsEndpoint = route('sales-leads.options', ['branch' => 'BRANCH_ID']);
@endphp
<div class="space-y-4" x-data="salesPocketbook()" @oasis-sync-updated.window="handleLifecycleSync($event.detail)">
    <x-crm.page-header
        variant="canonical"
        class="sales-pocketbook-page-header"
        eyebrow="{{ $monitoring ? 'Workspace Monitoring' : 'Workspace Pribadi' }}"
        title="Buku Saku Sales"
        description="{{ $monitoring ? 'Pantau aktivitas, funnel, dan kedisiplinan input Sales dalam scope organisasi Anda.' : 'Catat lead, jalankan agenda, dan lengkapi hasil aktivitas harian Anda.' }}"
    >
        @if(!$monitoring && $canCreate && $projects->isNotEmpty())
            <x-slot:actions>
                <x-crm.button variant="primary" accent="sales" :href="$tabUrl('leads').'#quick-lead-input'">Input Lead</x-crm.button>
                @if($canExport)
                    <x-crm.button variant="secondary" :href="$exportUrl">Export XLSX</x-crm.button>
                @endif
            </x-slot:actions>
        @elseif($canExport)
            <x-slot:actions>
                <x-crm.button variant="secondary" :href="$exportUrl">Export XLSX</x-crm.button>
            </x-slot:actions>
        @endif
    </x-crm.page-header>

    <div class="sales-pocketbook-scope" aria-label="Konteks Buku Saku Sales aktif">
        <div><span>Mode</span><strong>{{ $monitoring ? 'Monitoring' : 'Pribadi' }}</strong></div>
        <div><span>Cabang</span><strong>{{ $selectedBranch?->name ?? ($monitoring ? 'Semua dalam akses' : $personalBranchName) }}</strong></div>
        <div><span>Proyek</span><strong>{{ $selectedProject?->project_name ?? 'Semua dalam akses' }}</strong></div>
        <div><span>Sales</span><strong>{{ $monitoring ? ($selectedSales?->name ?? 'Semua dalam akses') : Auth::user()->name }}</strong></div>
    </div>
    @if($managerHierarchy)
        <x-crm.section id="manager-sales-hierarchy" title="Hierarki Tim Sales" description="Tim canonical dalam tanggung jawab Anda. Data operasional tetap dibatasi akses cabang dan proyek.">
            <div class="grid gap-3 md:grid-cols-3">
                <div><strong class="block text-sm">Supervisor</strong><span>{{ $managerHierarchy['supervisors']->pluck('name')->join(', ') ?: 'Belum ada' }}</span></div>
                <div><strong class="block text-sm">Koordinator</strong><span>{{ $managerHierarchy['coordinators']->pluck('name')->join(', ') ?: 'Belum ada' }}</span></div>
                <div><strong class="block text-sm">Sales</strong><span>{{ $managerHierarchy['sales']->pluck('name')->join(', ') ?: 'Belum ada' }}</span></div>
            </div>
        </x-crm.section>
    @endif
    <x-crm.page-presence page-key="sales-pocketbook" :branch-id="$selectedBranchId" />
    @if(Auth::user()->isSales())
        @include('crm.sales-pocketbook._daily-reminder')
    @endif

    @if($errors->any())
        <x-crm.alert variant="error" title="Data belum tersimpan." id="sales-pocketbook-validation-alert">
            Periksa kembali bidang yang ditandai. {{ $errors->first() }}
        </x-crm.alert>
    @endif
    @if(session('duplicate_warning'))
        <x-crm.alert variant="warning" title="Nomor ini juga ditemukan pada lead lain yang dapat Anda akses:" id="sales-pocketbook-duplicate-alert">
            <div class="sales-pocketbook-alert-list">
                @foreach(session('duplicate_warning') as $match)<div>{{ $match['sales'] }} / {{ $match['branch'] }} / {{ $match['project'] }} / {{ $match['date'] }}</div>@endforeach
            </div>
            <p class="sales-pocketbook-alert-note">Peringatan ini bersifat informatif. Lead tetap dapat disimpan.</p>
        </x-crm.alert>
    @endif

    @if($tab === 'leads' && $syncBranch)
        <x-crm.sync-status-panel module-key="sales-lead-lifecycle" :scope-name="$syncBranch->name" :branch-id="$syncBranch->id" :status="$lifecycleSyncStatus">
            <div class="flex flex-wrap items-center gap-2">
                @if($canReconcile)<x-crm.button variant="text" size="sm" :href="route('sales-pocketbook.lifecycle-reconciliations.index', ['branch_id' => $syncBranch->id, 'status' => 'open'])">Rekonsiliasi ({{ $reconciliationCount }})</x-crm.button>@endif
                <x-crm.sync-control module-key="sales-lead-lifecycle" module-name="{{ $monitoring ? 'Sinkronisasi Lead Cabang' : 'Sinkronisasi Lead Saya' }}" :scope-name="$syncBranch->name" :sync-url="route('sales-pocketbook.lifecycle-sync')" :status-url="route('sales-pocketbook.lifecycle-sync.status', ['branch_id' => $syncBranch->id])" :status="$lifecycleSyncStatus" :branch-id="$syncBranch->id" :can-sync="$canLifecycleSync" />
            </div>
        </x-crm.sync-status-panel>
    @endif
    @if($tab === 'leads' && $monitoring && !$syncBranch)
        <x-crm.alert variant="info" title="Pilih cabang untuk sinkronisasi">Status dan aksi sinkronisasi lead tersedia setelah satu cabang dipilih.</x-crm.alert>
    @endif
    <div x-show="syncUpdateAvailable" x-cloak role="status" aria-live="polite" class="border-2 border-black bg-[#fff7cc] p-3 text-sm">
        <strong>Data terbaru tersedia.</strong> Draf Anda tetap dipertahankan.
        <button type="button" class="ml-2 font-bold underline" @click="window.location.reload()">Muat ulang</button>
    </div>

    @if($branches->isEmpty())
        <x-crm.alert variant="error" title="Akses cabang belum tersedia" id="sales-pocketbook-assignment-alert">Anda belum memiliki akses cabang.</x-crm.alert>
    @elseif(Auth::user()->isSales() && $projects->isEmpty())
        <x-crm.alert variant="warning" title="Penugasan proyek diperlukan" id="sales-pocketbook-assignment-alert">Anda belum ditugaskan ke proyek. Hubungi admin pusat.</x-crm.alert>
    @endif

    <nav class="sales-pocketbook-tabs crm-horizontal-tabs" data-horizontal-tabs aria-label="Tampilan Buku Saku Sales">
        @foreach(['leads' => 'Lead', 'agenda' => 'Agenda', 'report' => 'Laporan'] as $key => $label)
            <a href="{{ $tabUrl($key) }}"
               @if($tab === $key) aria-current="page" @endif
               class="sales-pocketbook-tab {{ $tab === $key ? 'active' : '' }}">{{ $label }}</a>
        @endforeach
    </nav>

    @if($tab === 'report')
        @include('crm.sales-pocketbook._report')
    @elseif($tab === 'agenda')
    @php
        $agendaProjectId = old('project_id', request('project_id', $defaultProject?->id));
        $agendaProject = $projects->firstWhere('id', (int) $agendaProjectId);
        $agendaOwnerId = old('owner_user_id', Auth::user()->isSales()
            ? Auth::id()
            : $agendaProject?->assignedUsers->first(fn ($assigned) => $salesUsers->contains('id', $assigned->id))?->id);
        $hasQuickAgendaErrors = $errors->any() && (old('title') !== null || old('sales_activity_category') !== null || old('owner_user_id') !== null);
    @endphp
    @if($canCreateAgenda && $projects->isNotEmpty() && $salesUsers->isNotEmpty())
    <details id="quick-agenda-input" class="sales-agenda-quick {{ $monitoring ? 'sales-agenda-quick--monitoring' : 'sales-agenda-quick--primary' }}" @if(!$monitoring || $hasQuickAgendaErrors) open @endif>
        <summary class="sales-agenda-quick-summary">
            <span class="sales-agenda-quick-eyebrow">{{ $monitoring ? 'Input operasional' : 'Agenda berikutnya' }}</span>
            <span class="sales-agenda-quick-title">Isi Agenda Baru</span>
            <span class="sales-agenda-quick-description">{{ $monitoring ? 'Buka formulir untuk mencatat agenda Sales dalam scope Anda.' : 'Rencanakan aktivitas, waktu, dan konteks kunjungan atau tindak lanjut.' }}</span>
        </summary>
        <form method="POST" action="{{ route('sales-agendas.store') }}" x-data="salesCascade(@js($cascadeProjects), @js($cascadeSales), @js(['branch' => old('branch_id', $defaultProject?->branch_id), 'project' => $agendaProjectId, 'sales' => $agendaOwnerId]))" @submit="setSubmitting()" class="sales-pocketbook-quick-form sales-agenda-quick-form" aria-describedby="quick-agenda-required-note">
            @csrf
            <p id="quick-agenda-required-note" class="sales-pocketbook-required-note sm:col-span-2 xl:col-span-4"><span aria-hidden="true">*</span> Wajib diisi</p>
            <x-crm.field label="Tanggal Agenda" for="quick-agenda-scheduled-date" required :error="$errors->first('scheduled_date')"><x-crm.date-field id="quick-agenda-scheduled-date" name="scheduled_date" :value="old('scheduled_date', now()->toDateString())" required :aria-invalid="$errors->has('scheduled_date') ? 'true' : 'false'" :aria-describedby="$errors->has('scheduled_date') ? 'quick-agenda-scheduled-date-error' : null" /></x-crm.field>
            <x-crm.field label="Jam Mulai" for="quick-agenda-start-time" required :error="$errors->first('start_time')"><x-crm.time-field id="quick-agenda-start-time" name="start_time" :value="old('start_time')" required :aria-invalid="$errors->has('start_time') ? 'true' : 'false'" :aria-describedby="$errors->has('start_time') ? 'quick-agenda-start-time-error' : null" /></x-crm.field>
            <x-crm.field label="Jam Selesai" for="quick-agenda-end-time" required :error="$errors->first('end_time')"><x-crm.time-field id="quick-agenda-end-time" name="end_time" :value="old('end_time')" required :aria-invalid="$errors->has('end_time') ? 'true' : 'false'" :aria-describedby="$errors->has('end_time') ? 'quick-agenda-end-time-error' : null" /></x-crm.field>
            <x-crm.field label="Kategori Aktivitas" for="quick-agenda-category" required :error="$errors->first('sales_activity_category')"><select id="quick-agenda-category" class="sales-input" name="sales_activity_category" required aria-invalid="{{ $errors->has('sales_activity_category') ? 'true' : 'false' }}" @if($errors->has('sales_activity_category')) aria-describedby="quick-agenda-category-error" @endif><option value="">Pilih kategori</option>@foreach(\App\Models\ContentItem::SALES_ACTIVITY_CATEGORIES as $category)<option value="{{ $category }}" @selected(old('sales_activity_category') === $category)>{{ $category }}</option>@endforeach</select></x-crm.field>
            <x-crm.field label="Judul Agenda" for="quick-agenda-title" required :error="$errors->first('title')"><input id="quick-agenda-title" class="sales-input" name="title" value="{{ old('title') }}" required aria-invalid="{{ $errors->has('title') ? 'true' : 'false' }}" @if($errors->has('title')) aria-describedby="quick-agenda-title-error" @endif></x-crm.field>
            <x-crm.field label="Cabang" for="quick-agenda-branch" required :error="$errors->first('branch_id')"><select id="quick-agenda-branch" class="sales-input" name="branch_id" x-model="branch" @change="branchChanged()" required aria-invalid="{{ $errors->has('branch_id') ? 'true' : 'false' }}" @if($errors->has('branch_id')) aria-describedby="quick-agenda-branch-error" @endif>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></x-crm.field>
            <x-crm.field label="Proyek" for="quick-agenda-project" required :error="$errors->first('project_id')"><select id="quick-agenda-project" class="sales-input" name="project_id" x-model="project" @change="projectChanged()" required aria-invalid="{{ $errors->has('project_id') ? 'true' : 'false' }}" @if($errors->has('project_id')) aria-describedby="quick-agenda-project-error" @endif><option value="">Pilih proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" x-show="projectVisible('{{ $project->id }}')" :disabled="!projectVisible('{{ $project->id }}')">{{ $project->project_name }}</option>@endforeach</select></x-crm.field>
            <x-crm.field label="Sales" for="quick-agenda-owner" required :error="$errors->first('owner_user_id')"><select id="quick-agenda-owner" class="sales-input" name="owner_user_id" x-model="sales" required @disabled(!$monitoring) aria-invalid="{{ $errors->has('owner_user_id') ? 'true' : 'false' }}" @if($errors->has('owner_user_id')) aria-describedby="quick-agenda-owner-error" @endif>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" x-show="salesVisible('{{ $sales->id }}')" :disabled="!salesVisible('{{ $sales->id }}')">{{ $sales->name }}</option>@endforeach</select>@unless($monitoring)<input type="hidden" name="owner_user_id" value="{{ Auth::id() }}">@endunless</x-crm.field>
            <x-crm.field label="Lokasi" for="quick-agenda-location" :error="$errors->first('location')"><input id="quick-agenda-location" class="sales-input" name="location" value="{{ old('location') }}" aria-invalid="{{ $errors->has('location') ? 'true' : 'false' }}" @if($errors->has('location')) aria-describedby="quick-agenda-location-error" @endif></x-crm.field>
            <x-crm.field label="Catatan" for="quick-agenda-notes" :error="$errors->first('notes')" class="sm:col-span-2 xl:col-span-3"><textarea id="quick-agenda-notes" class="sales-input" name="notes" rows="2" aria-invalid="{{ $errors->has('notes') ? 'true' : 'false' }}" @if($errors->has('notes')) aria-describedby="quick-agenda-notes-error" @endif>{{ old('notes') }}</textarea></x-crm.field>
            <div class="sales-pocketbook-form-actions xl:col-span-4"><x-crm.button type="submit" variant="primary" accent="sales" x-bind:disabled="submitting" x-bind:aria-busy="submitting"><span x-show="!submitting">Simpan Agenda</span><span x-show="submitting" x-cloak>Menyimpan agenda...</span></x-crm.button><span class="sr-only" aria-live="polite" x-text="submitting ? 'Agenda sedang disimpan.' : ''"></span></div>
        </form>
    </details>
    @endif

    <x-crm.toolbar label="Filter agenda" class="sales-agenda-toolbar">
        <div class="sales-agenda-filter-summary">
            <strong>Daftar agenda</strong>
            <span>{{ $agendas->total() }} agenda ditemukan{{ $activeAgendaFilterCount ? ' dengan '.$activeAgendaFilterCount.' filter aktif' : ' pada minggu berjalan' }}</span>
        </div>
        @if($selectedBranch)<x-crm.filter-chip label="Cabang: {{ $selectedBranch->name }}" :remove-href="$agendaFilterUrl(['branch_id'])" remove-label="Hapus filter cabang" />@endif
        @if($selectedProject)<x-crm.filter-chip label="Proyek: {{ $selectedProject->project_name }}" :remove-href="$agendaFilterUrl(['project_id'])" remove-label="Hapus filter proyek" />@endif
        @if($selectedSales)<x-crm.filter-chip label="Sales: {{ $selectedSales->name }}" :remove-href="$agendaFilterUrl(['sales_user_id'])" remove-label="Hapus filter sales" />@endif
        @if($completedAgendaDrilldown)<x-crm.filter-chip label="Drilldown: Agenda selesai" :remove-href="$agendaFilterUrl(['report_agenda_completed'])" remove-label="Hapus drilldown agenda selesai" />@endif
        @if($missingAgendaResultDrilldown)<x-crm.filter-chip label="Drilldown: Hasil belum lengkap" :remove-href="$agendaFilterUrl(['report_agenda_missing_result'])" remove-label="Hapus drilldown hasil belum lengkap" />@endif
        @if($hasExplicitAgendaPeriod)<x-crm.filter-chip label="Periode: {{ $reportPeriod['start']->format('d/m/Y') }} - {{ $reportPeriod['end']->format('d/m/Y') }}" :remove-href="$agendaFilterUrl(['period_type', 'week', 'date_from', 'date_to'])" remove-label="Kembali ke minggu berjalan" />@endif
        <x-slot:actions>
            @if($hasAgendaFilters)<x-crm.button variant="text" :href="$agendaResetUrl">Hapus semua filter</x-crm.button>@endif
        </x-slot:actions>
    </x-crm.toolbar>

    <form method="GET" action="{{ route('sales-pocketbook.index') }}" x-data="salesCascade(@js($cascadeProjects), @js($cascadeSales), @js(['branch' => request('branch_id'), 'project' => request('project_id'), 'sales' => request('sales_user_id')]))" class="sales-agenda-filter-form">
        <input type="hidden" name="tab" value="agenda">
        @if($completedAgendaDrilldown)<input type="hidden" name="report_agenda_completed" value="1">@endif
        @if($missingAgendaResultDrilldown)<input type="hidden" name="report_agenda_missing_result" value="1">@endif
        @if($monitoring)<x-crm.field label="Cabang" for="agenda-filter-branch"><select id="agenda-filter-branch" class="sales-input" name="branch_id" x-model="branch" @change="branchChanged()"><option value="">Semua cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></x-crm.field>@endif
        <x-crm.field label="Proyek" for="agenda-filter-project"><select id="agenda-filter-project" class="sales-input" name="project_id" x-model="project" @change="projectChanged()"><option value="">Semua proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" x-show="projectVisible('{{ $project->id }}')" :disabled="!projectVisible('{{ $project->id }}')">{{ $project->project_name }}</option>@endforeach</select></x-crm.field>
        @if($monitoring)<x-crm.field label="Sales" for="agenda-filter-sales"><select id="agenda-filter-sales" class="sales-input" name="sales_user_id" x-model="sales"><option value="">Semua sales</option>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" x-show="salesVisible('{{ $sales->id }}')" :disabled="!salesVisible('{{ $sales->id }}')">{{ $sales->name }}</option>@endforeach</select></x-crm.field>@endif
        @if($missingAgendaResultDrilldown)
            <x-crm.alert variant="info" title="Antrean seluruh periode">Filter periode tidak diterapkan agar semua agenda selesai yang belum memiliki hasil tetap terlihat.</x-crm.alert>
        @else
            <div class="crm-field"><span class="crm-field-label" id="agenda-period-label">Periode</span><div class="crm-field-control">@include('crm.sales-pocketbook._period-picker', ['periodPickerId' => 'agenda-period'])</div></div>
        @endif
        <div class="sales-agenda-filter-actions"><x-crm.button type="submit" variant="primary" accent="sales">Terapkan Filter</x-crm.button>@if($hasAgendaFilters)<x-crm.button variant="secondary" :href="$agendaResetUrl">Hapus semua filter</x-crm.button>@endif</div>
    </form>

    <section class="sales-agenda-monitor">
        <header class="sales-agenda-monitor-header"><h2>{{ $monitoring ? 'Monitoring Agenda' : 'Agenda Saya' }}</h2><span>{{ $agendas->total() }} agenda</span></header>
        <div class="sales-agenda-list">
            @forelse($agendas as $agenda)
            @php
                $needsMissingResult = $agenda->status === 'done' && blank(trim((string) $agenda->activity_result));
                [$agendaStatusLabel, $agendaStatusVariant] = match (true) {
                    $needsMissingResult => ['Selesai / Hasil belum lengkap', 'warning'],
                    $agenda->status === 'done' => ['Selesai', 'success'],
                    $agenda->status === 'rescheduled' => ['Dijadwalkan ulang', 'info'],
                    $agenda->status === 'cancelled' => ['Dibatalkan', 'inactive'],
                    $agenda->scheduled_date->isToday() && $agenda->status === 'confirmed' => ['Dikonfirmasi / Hari ini', 'processing'],
                    $agenda->scheduled_date->isToday() => ['Terjadwal / Hari ini', 'pending'],
                    $agenda->scheduled_date->isFuture() && $agenda->status === 'confirmed' => ['Dikonfirmasi / Akan datang', 'processing'],
                    $agenda->scheduled_date->isFuture() => ['Terjadwal / Akan datang', 'info'],
                    $agenda->status === 'confirmed' => ['Dikonfirmasi / Terlewat', 'warning'],
                    default => ['Terjadwal / Terlewat', 'warning'],
                };
            @endphp
            <article class="sales-agenda-item">
                <div class="sales-agenda-identity"><div><span class="sales-agenda-kicker">{{ $agenda->sales_activity_category ?: 'Aktivitas Sales' }}</span><h3>{{ $agenda->title }}</h3></div><x-crm.status-badge :variant="$agendaStatusVariant">{{ $agendaStatusLabel }}</x-crm.status-badge></div>
                <dl class="sales-agenda-facts">
                    <div><dt>Jadwal</dt><dd>{{ $agenda->scheduled_date->format('d/m/Y') }}, {{ substr($agenda->start_time, 0, 5) }}@if($agenda->end_time) - {{ substr($agenda->end_time, 0, 5) }}@endif</dd></div>
                    <div><dt>Durasi</dt><dd>{{ $agenda->duration_minutes }} menit</dd></div>
                    <div><dt>Proyek</dt><dd>{{ $agenda->project_name ?: 'Belum tersedia' }}</dd></div>
                    <div><dt>Lokasi</dt><dd>{{ $agenda->location ?: 'Tidak dicatat' }}</dd></div>
                    @if($monitoring)<div><dt>Cabang / Sales</dt><dd>{{ $agenda->branch?->name ?: 'Tidak tersedia' }} / {{ $agenda->owner?->name ?: 'Tidak tersedia' }}</dd></div>@endif
                    @if($agenda->status === 'done' && $agenda->completed_at)<div><dt>Selesai pada</dt><dd>{{ $agenda->completed_at->format('d/m/Y H:i') }}</dd></div>@endif
                </dl>
                @if($agenda->rescheduledFrom)
                    <x-crm.alert variant="info" title="Agenda pengganti" class="sales-agenda-linkage">Dijadwalkan ulang dari agenda tanggal {{ $agenda->rescheduledFrom->scheduled_date?->format('d/m/Y') ?? 'yang tidak lagi tersedia' }}.</x-crm.alert>
                @endif
                @if($agenda->notes)<div class="sales-agenda-notes"><strong>Catatan</strong><p class="whitespace-pre-line">{{ $agenda->notes }}</p></div>@endif
                @if($agenda->activity_result)<div class="sales-agenda-result"><strong>Hasil Aktivitas</strong><p class="whitespace-pre-line">{{ $agenda->activity_result }}</p></div>@endif
                @if($canManageAgenda && (!in_array($agenda->status, ['done', 'cancelled', 'rescheduled'], true) || $needsMissingResult))
                    <div class="sales-agenda-action-grid">
                        <form method="POST" action="{{ route('sales-agendas.update', $agenda) }}" x-data="{ submitting: false }" @submit="submitting = true" class="sales-agenda-action-form">@csrf @method('PATCH')<input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($agenda) }}"><x-crm.field label="Hasil Aktivitas" for="agenda-result-{{ $agenda->id }}" required><textarea id="agenda-result-{{ $agenda->id }}" class="sales-input" name="activity_result" rows="3" required></textarea></x-crm.field><x-crm.button type="submit" variant="primary" accent="sales" x-bind:disabled="submitting" x-bind:aria-busy="submitting"><span x-show="!submitting">Tandai Selesai</span><span x-show="submitting" x-cloak>Menyimpan hasil...</span></x-crm.button><span class="sr-only" aria-live="polite" x-text="submitting ? 'Hasil agenda sedang disimpan.' : ''"></span></form>
                        @unless($needsMissingResult)
                        <form method="POST" action="{{ route('sales-agendas.reschedule', $agenda) }}" x-data="{ submitting: false }" @submit="submitting = true" class="sales-agenda-action-form">@csrf<input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($agenda) }}"><x-crm.field label="Tanggal Baru" for="agenda-reschedule-date-{{ $agenda->id }}" required><x-crm.date-field id="agenda-reschedule-date-{{ $agenda->id }}" name="scheduled_date" required /></x-crm.field><div class="sales-agenda-time-grid"><x-crm.field label="Jam Mulai" for="agenda-reschedule-start-{{ $agenda->id }}" required><x-crm.time-field id="agenda-reschedule-start-{{ $agenda->id }}" name="start_time" required /></x-crm.field><x-crm.field label="Jam Selesai" for="agenda-reschedule-end-{{ $agenda->id }}" required><x-crm.time-field id="agenda-reschedule-end-{{ $agenda->id }}" name="end_time" required /></x-crm.field></div><x-crm.button type="submit" variant="secondary" x-bind:disabled="submitting" x-bind:aria-busy="submitting"><span x-show="!submitting">Jadwalkan Ulang</span><span x-show="submitting" x-cloak>Menjadwalkan...</span></x-crm.button><span class="sr-only" aria-live="polite" x-text="submitting ? 'Agenda sedang dijadwalkan ulang.' : ''"></span></form>
                        @endunless
                    </div>
                @endif
                @if(auth()->user()->hasPermission('comments.view'))<footer class="sales-agenda-footer"><x-crm.button variant="text" :href="route('comments.thread', ['alias' => 'sales-agenda', 'id' => $agenda->id])">Komentar ({{ $agenda->comments_count }})</x-crm.button></footer>@endif
            </article>
            @empty
                <x-crm.empty-state
                    :title="$missingAgendaResultDrilldown ? 'Semua hasil agenda sudah lengkap.' : ($hasAgendaFilters ? 'Tidak ada agenda yang cocok dengan filter.' : 'Belum ada agenda minggu ini.')"
                    :description="$missingAgendaResultDrilldown ? 'Tidak ada agenda selesai yang masih membutuhkan hasil aktivitas.' : ($hasAgendaFilters ? 'Ubah atau hapus filter untuk melihat agenda lain dalam akses Anda.' : 'Agenda pada minggu berjalan akan muncul di daftar ini.')"
                >
                    @if($hasAgendaFilters)
                        <x-slot:actions>
                            <x-crm.button variant="secondary" :href="$agendaResetUrl">Hapus semua filter</x-crm.button>
                        </x-slot:actions>
                    @endif
                </x-crm.empty-state>
            @endforelse
        </div>
        <x-crm.pagination :collection="$agendas" :show-per-page="false" strip-query-key="page" />
    </section>
    @else
    @if($canCreate && $projects->isNotEmpty())
    @php
        $quickProjectId = old('project_id', request('project_id', $defaultProject?->id));
        $quickBranchId = old('branch_id', $defaultProject?->branch_id ?? Auth::user()->branch_id);
        $quickSalesId = old('sales_user_id', Auth::user()->isSales() ? Auth::id() : null);
    @endphp
    <section id="quick-lead-input" class="border-2 border-black bg-white">
        <div class="bg-black text-[#fcc20f] px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">+ Input Lead Hari Ini</div>
        <form method="POST" action="{{ route('sales-leads.store') }}" x-data="salesCascade(@js($cascadeProjects), @js($cascadeSales), @js(['branch' => $quickBranchId ?? null, 'project' => $quickProjectId ?? null, 'sales' => $quickSalesId ?? null, 'optionsEndpoint' => $leadOptionsEndpoint, 'source' => old('source'), 'platform' => old('platform'), 'campaignName' => old('campaign_name'), 'promo' => old('id_promo')]))" @submit="setSubmitting()" class="sales-pocketbook-quick-form p-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3" aria-describedby="quick-lead-required-note">
            @csrf
            <input type="hidden" name="operation_uuid" value="{{ old('operation_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
            <p id="quick-lead-required-note" class="sales-pocketbook-required-note md:col-span-2 xl:col-span-4"><span aria-hidden="true">*</span> Wajib diisi</p>
            <x-crm.field label="Tanggal Lead" for="quick-lead-date" required :error="$errors->first('lead_date')"><x-crm.date-field id="quick-lead-date" name="lead_date" :value="old('lead_date', request('lead_date', now()->toDateString()))" required :aria-invalid="$errors->has('lead_date') ? 'true' : 'false'" :aria-describedby="$errors->has('lead_date') ? 'quick-lead-date-error' : null" /></x-crm.field>
            <x-crm.field label="Nama Calon Konsumen" for="quick-lead-customer-name" required :error="$errors->first('customer_name')"><input id="quick-lead-customer-name" class="sales-input" name="customer_name" value="{{ old('customer_name') }}" required aria-invalid="{{ $errors->has('customer_name') ? 'true' : 'false' }}" @if($errors->has('customer_name')) aria-describedby="quick-lead-customer-name-error" @endif></x-crm.field>
            <x-crm.field label="No. WhatsApp / Telepon" for="quick-lead-phone" hint="Pemeriksaan duplikat hanya berupa peringatan dan tidak mencegah penyimpanan." :error="$errors->first('phone')">
                <input id="quick-lead-phone" class="sales-input" name="phone" value="{{ old('phone') }}" @blur="checkPhone($event.target.value)" x-bind:aria-busy="duplicatePending" aria-invalid="{{ $errors->has('phone') ? 'true' : 'false' }}" aria-describedby="quick-lead-phone-hint{{ $errors->has('phone') ? ' quick-lead-phone-error' : '' }} quick-lead-duplicate-status">
                <div id="quick-lead-duplicate-status" class="sales-pocketbook-duplicate-status" aria-live="polite" aria-atomic="true">
                    <p x-show="duplicatePending" x-cloak>Memeriksa nomor duplikat...</p>
                    <div x-show="!duplicatePending && duplicates.length" x-cloak class="sales-pocketbook-duplicate-result"><strong>Peringatan duplikat, lead tetap dapat disimpan.</strong><template x-for="item in duplicates" :key="item.id"><div x-text="`${item.sales} / ${item.branch} / ${item.project} / ${item.date}`"></div></template></div>
                </div>
            </x-crm.field>
            <x-crm.field label="Sumber Lead" for="quick-lead-source" required :error="$errors->first('source')"><select id="quick-lead-source" class="sales-input" name="source" x-model="source" required><option value="">Pilih sumber</option><template x-for="option in sheetOptions.source" :key="option"><option :value="option" x-text="option"></option></template></select><p x-show="historicalSource" x-cloak class="mt-1 text-xs text-amber-800">Nilai sebelumnya “<span x-text="historicalSource"></span>” tidak tersedia lagi. Pilih sumber aktif sebelum menyimpan.</p></x-crm.field>
            <x-crm.field label="Kanal Masuk" for="quick-lead-platform" required :error="$errors->first('platform')"><select id="quick-lead-platform" class="sales-input" name="platform" x-model="platform" required><option value="">Pilih kanal</option><template x-if="platform && !sheetOptions.channel.includes(platform)"><option :value="platform" x-text="platform"></option></template><template x-for="option in sheetOptions.channel" :key="option"><option :value="option" x-text="option"></option></template></select></x-crm.field>
            <x-crm.field label="Aktivitas Lead" for="quick-lead-campaign" required :error="$errors->first('campaign_name')"><select id="quick-lead-campaign" class="sales-input" name="campaign_name" x-model="campaignName" required><option value="">Pilih aktivitas</option><template x-if="campaignName && !sheetOptions.activity.includes(campaignName)"><option :value="campaignName" x-text="campaignName"></option></template><template x-for="option in sheetOptions.activity" :key="option"><option :value="option" x-text="option"></option></template></select></x-crm.field>
            <x-crm.field label="Nama Promo" for="quick-lead-promo" :error="$errors->first('id_promo')"><select id="quick-lead-promo" class="sales-input" name="id_promo" x-model="promo"><option value="">Pilih promo (opsional)</option><template x-if="promo && !sheetOptions.promo.includes(promo)"><option :value="promo" x-text="promo"></option></template><template x-for="option in sheetOptions.promo" :key="option"><option :value="option" x-text="option"></option></template></select></x-crm.field>
            <p x-show="optionsLoading || optionsError" class="text-xs md:col-span-2 xl:col-span-4" :class="optionsError ? 'text-red-700' : ''" x-text="optionsError || 'Memuat pilihan spreadsheet cabang...'"></p>
            <x-crm.field label="Status Lead" for="quick-lead-status" required :error="$errors->first('current_status')"><select id="quick-lead-status" class="sales-input" name="current_status" required><option value="no_response" @selected(old('current_status', 'no_response') === 'no_response')>No Respon</option><option value="discussion" @selected(old('current_status') === 'discussion')>Diskusi</option><option value="site_visit" @selected(old('current_status') === 'site_visit')>Cek Lokasi</option></select></x-crm.field>
            <x-crm.field label="Cabang" for="quick-lead-branch" required :error="$errors->first('branch_id')"><select id="quick-lead-branch" class="sales-input" name="branch_id" x-model="branch" required @change="branchChanged()" aria-invalid="{{ $errors->has('branch_id') ? 'true' : 'false' }}" @if($errors->has('branch_id')) aria-describedby="quick-lead-branch-error" @endif>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></x-crm.field>
            <x-crm.field label="Proyek" for="quick-lead-project" required :error="$errors->first('project_id')"><select id="quick-lead-project" class="sales-input" name="project_id" x-model="project" required @change="projectChanged()" aria-invalid="{{ $errors->has('project_id') ? 'true' : 'false' }}" @if($errors->has('project_id')) aria-describedby="quick-lead-project-error" @endif><option value="">Pilih proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" data-branch="{{ $project->branch_id }}" @selected($quickProjectId == $project->id) x-show="projectVisible('{{ $project->id }}')" :disabled="!projectVisible('{{ $project->id }}')">{{ $project->project_name }}</option>@endforeach</select></x-crm.field>
            <x-crm.field label="Sales" for="quick-lead-sales" required :error="$errors->first('sales_user_id')"><select id="quick-lead-sales" class="sales-input" name="sales_user_id" x-model="sales" required @disabled(!$monitoring) aria-invalid="{{ $errors->has('sales_user_id') ? 'true' : 'false' }}" @if($errors->has('sales_user_id')) aria-describedby="quick-lead-sales-error" @endif>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" x-show="salesVisible('{{ $sales->id }}')" :disabled="!salesVisible('{{ $sales->id }}')">{{ $sales->name }}</option>@endforeach</select>@unless($monitoring)<input type="hidden" name="sales_user_id" value="{{ Auth::id() }}">@endunless</x-crm.field>
            <x-crm.field label="Catatan" for="quick-lead-notes" :error="$errors->first('notes')"><input id="quick-lead-notes" class="sales-input" name="notes" value="{{ old('notes') }}" aria-invalid="{{ $errors->has('notes') ? 'true' : 'false' }}" @if($errors->has('notes')) aria-describedby="quick-lead-notes-error" @endif></x-crm.field>
            <div class="sales-pocketbook-form-actions xl:col-span-4">
                <x-crm.button type="submit" variant="primary" accent="sales" name="submit_action" value="save" x-bind:disabled="submitting" x-bind:aria-busy="submitting"><span x-show="!submitting">Simpan</span><span x-show="submitting" x-cloak>Menyimpan...</span></x-crm.button>
                <x-crm.button type="submit" variant="secondary" name="submit_action" value="add_another" x-bind:disabled="submitting" x-bind:aria-busy="submitting">Simpan &amp; Tambah Lagi</x-crm.button>
                <span class="sr-only" aria-live="polite" x-text="submitting ? 'Lead sedang disimpan.' : ''"></span>
            </div>
        </form>
    </section>
    @endif

    @if($monitoring)
    <x-crm.toolbar label="Filter monitoring lead" class="sales-lead-toolbar">
        <div class="sales-lead-filter-summary">
            <strong>Filter monitoring</strong>
            <span>{{ $activeLeadFilterCount ? $activeLeadFilterCount.' filter aktif' : 'Menampilkan seluruh lead dalam akses Anda' }}</span>
        </div>
        @if($selectedBranch)<x-crm.filter-chip label="Cabang: {{ $selectedBranch->name }}" :remove-href="$leadFilterUrl(['branch_id'])" remove-label="Hapus filter cabang" />@endif
        @if($selectedProject)<x-crm.filter-chip label="Proyek: {{ $selectedProject->project_name }}" :remove-href="$leadFilterUrl(['project_id'])" remove-label="Hapus filter proyek" />@endif
        @if($selectedSales)<x-crm.filter-chip label="Sales: {{ $selectedSales->name }}" :remove-href="$leadFilterUrl(['sales_user_id'])" remove-label="Hapus filter sales" />@endif
        @if($selectedLeadSource)<x-crm.filter-chip label="Sumber: {{ $selectedLeadSource }}" :remove-href="$leadFilterUrl(['lead_source', 'lead_source_id'])" remove-label="Hapus filter sumber lead" />@endif
        @if($selectedLeadStage)<x-crm.filter-chip label="Tahap: {{ $selectedLeadStage }}" :remove-href="$leadFilterUrl(['stage'])" remove-label="Hapus filter tahap" />@endif
        @if($selectedReportMetric)<x-crm.filter-chip label="Drilldown: {{ $selectedReportMetric }}" :remove-href="$leadFilterUrl(['report_metric'])" remove-label="Hapus drilldown laporan" />@endif
        @if($hasLeadPeriodFilter)<x-crm.filter-chip label="Periode: {{ $reportPeriod['start']->format('d/m/Y') }} - {{ $reportPeriod['end']->format('d/m/Y') }}" :remove-href="$leadFilterUrl(['period_type', 'week', 'date_from', 'date_to'])" remove-label="Hapus filter periode" />@endif
        <x-slot:actions>
            @if($hasLeadFilters)<x-crm.button variant="text" :href="$leadResetUrl">Hapus semua filter</x-crm.button>@endif
            <x-crm.button variant="secondary" @click="$dispatch('oasis:modal-open', { name: 'sales-lead-filters' })">Filter lanjutan{{ $activeLeadFilterCount ? ' ('.$activeLeadFilterCount.')' : '' }}</x-crm.button>
        </x-slot:actions>
    </x-crm.toolbar>

    <x-crm.modal name="sales-lead-filters" title="Filter Monitoring Lead" description="Batasi daftar berdasarkan organisasi, sumber, tahap, dan periode." size="lg">
        <form method="GET" action="{{ route('sales-pocketbook.index') }}" x-data="salesCascade(@js($cascadeProjects), @js($cascadeSales), @js(['branch' => request('branch_id'), 'project' => request('project_id'), 'sales' => request('sales_user_id')]))" class="sales-lead-filter-form">
            <input type="hidden" name="tab" value="leads">
            @if(request()->filled('report_metric'))<input type="hidden" name="report_metric" value="{{ request('report_metric') }}">@endif
            <x-crm.field label="Cabang" for="lead-filter-branch"><select id="lead-filter-branch" class="sales-input" name="branch_id" x-model="branch" @change="branchChanged()"><option value="">Semua cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></x-crm.field>
            <x-crm.field label="Proyek" for="lead-filter-project"><select id="lead-filter-project" class="sales-input" name="project_id" x-model="project" @change="projectChanged()"><option value="">Semua proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" x-show="projectVisible('{{ $project->id }}')" :disabled="!projectVisible('{{ $project->id }}')">{{ $project->project_name }}</option>@endforeach</select></x-crm.field>
            <x-crm.field label="Sales" for="lead-filter-sales"><select id="lead-filter-sales" class="sales-input" name="sales_user_id" x-model="sales"><option value="">Semua sales</option>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" x-show="salesVisible('{{ $sales->id }}')" :disabled="!salesVisible('{{ $sales->id }}')">{{ $sales->name }}</option>@endforeach</select></x-crm.field>
            <x-crm.field label="Sumber Lead" for="lead-filter-source"><select id="lead-filter-source" class="sales-input" name="lead_source"><option value="">Semua sumber</option>@foreach($leadSourceOptions as $source)<option value="{{ $source }}" @selected($selectedLeadSource === $source)>{{ $source }}</option>@endforeach</select></x-crm.field>
            <x-crm.field label="Tahap Saat Ini" for="lead-filter-stage"><select id="lead-filter-stage" class="sales-input" name="stage"><option value="">Semua tahap</option>@foreach(\App\Models\SalesLead::STAGES as $stage => $label)<option value="{{ $stage }}" @selected(request('stage') === $stage)>{{ $label }}</option>@endforeach</select></x-crm.field>
            <div class="crm-field"><span class="crm-field-label" id="lead-period-label">Periode</span><div class="crm-field-control">@include('crm.sales-pocketbook._period-picker', ['periodPickerId' => 'lead-period'])</div></div>
            <div class="sales-lead-filter-actions">
                <x-crm.button type="submit" variant="primary" accent="sales">Terapkan Filter</x-crm.button>
                <x-crm.button variant="secondary" :href="$leadResetUrl">Hapus semua filter</x-crm.button>
            </div>
        </form>
    </x-crm.modal>
    @endif

    <section class="sales-lead-monitor">
        <header class="sales-lead-monitor-header"><h2>{{ $monitoring ? 'Monitoring Lead' : 'Lead Saya' }}</h2><span>{{ $leads->total() }} lead</span></header>
        <div class="sales-lead-list">
            @forelse($leads as $lead)
            @php
                $leadStage = $lead->currentStage();
                $leadActivityAt = $lead->lastActivityAt();
                $leadStageVariant = $leadStage === 'akad_at' ? 'success' : ($leadStage ? 'processing' : 'pending');
                $leadEditPayload = ['id' => $lead->id, 'url' => route('sales-leads.update', $lead), 'fallback_url' => route('sales-leads.edit', $lead), 'site_visit_modal' => 'lead-site-visit-'.$lead->id, 'token' => app(\App\Services\OptimisticLockService::class)->token($lead), 'branch_id' => (string) $lead->branch_id, 'project_id' => (string) $lead->project_id, 'sales_user_id' => (string) $lead->sales_user_id, 'source' => $lead->source, 'platform' => $lead->platform, 'campaign_name' => $lead->campaign_name, 'id_promo' => $lead->id_promo, 'current_status' => $lead->current_status->value, 'current_status_label' => $lead->current_status->label(), 'status_manual' => $lead->current_status->isManual(), 'lead_date' => $lead->lead_date->toDateString(), 'customer_name' => $lead->customer_name, 'phone' => $lead->phone, 'notes' => $lead->notes, 'linked_consumer_reference' => $lead->linked_consumer_reference];
            @endphp
            <article class="sales-lead-item" data-lead-row="{{ $lead->id }}">
                <div class="sales-lead-identity">
                    <div><span class="sales-lead-kicker">Lead <span data-lead-field="date">{{ $lead->lead_date->format('d/m/Y') }}</span></span><h3 data-lead-field="name">{{ $lead->customer_name }}</h3></div>
                    <x-crm.status-badge :variant="$leadStageVariant" data-stage-label="{{ $lead->id }}">{{ $lead->currentStageLabel() }}</x-crm.status-badge>
                </div>
                <dl class="sales-lead-facts">
                    <div><dt>Telepon</dt><dd data-lead-field="phone">{{ $lead->phone ?: '—' }}</dd></div>
                    <div><dt>Proyek</dt><dd data-lead-field="project">{{ $lead->project?->project_name ?: '—' }}</dd></div>
                    <div><dt>Sumber</dt><dd data-lead-field="source">{{ $lead->effective_source ?: '—' }}</dd></div>
                    <div><dt>Cabang / Sales</dt><dd data-lead-field="assignment">{{ $lead->branch?->name }} / {{ $lead->sales?->name }}</dd></div>
                    <div><dt>Platform / Campaign</dt><dd>{{ $lead->platform ?: '—' }} / {{ $lead->campaign_name ?: '—' }}</dd></div>
                    <div class="sales-lead-activity"><dt>Aktivitas terbaru</dt><dd><strong data-lead-activity-label="{{ $lead->id }}">{{ $leadStage ? 'Tahap '.$lead->currentStageLabel() : 'Lead dicatat' }}</strong><span data-lead-activity-time="{{ $lead->id }}" data-activity-stage="{{ $leadStage }}" data-lead-baseline="{{ $lead->lead_date->format('d/m/Y') }} 00:00">{{ $leadActivityAt?->format('d/m/Y H:i') ?: '—' }}</span></dd></div>
                </dl>
                @include('crm.sales-pocketbook._lead-lifecycle', ['lead' => $lead])
                @can('updateStage', $lead)<div class="sales-lead-progress"><span class="sales-lead-section-label">Perbarui progres</span>@include('crm.sales-pocketbook._stage-controls', ['lead' => $lead])</div>@endcan
                <footer class="sales-lead-actions">
                    @if(auth()->user()->hasPermission('comments.view'))<x-crm.button variant="text" :href="route('comments.thread', ['alias' => 'sales-lead', 'id' => $lead->id])">Komentar ({{ $lead->comments_count }})</x-crm.button>@endif
                    @can('update', $lead)<button type="button" class="crm-button crm-button--text crm-button--md" @click="openLeadEdit(@js($leadEditPayload))">Edit lead</button>@endcan
                </footer>
            </article>
            @empty
                <x-crm.empty-state
                    :title="$hasLeadFilters ? 'Tidak ada lead yang cocok dengan filter.' : 'Belum ada lead.'"
                    :description="$hasLeadFilters ? 'Ubah atau hapus filter untuk melihat lead lain dalam akses Anda.' : 'Lead yang dicatat akan muncul di daftar ini.'"
                >
                    @if($hasLeadFilters)
                        <x-slot:actions>
                            <x-crm.button variant="secondary" :href="$leadResetUrl">Hapus semua filter</x-crm.button>
                        </x-slot:actions>
                    @endif
                </x-crm.empty-state>
            @endforelse
        </div>
        <x-crm.pagination :collection="$leads" :show-per-page="false" strip-query-key="agenda_page" />
    </section>
    @endif

    <div x-show="leadModalOpen" x-cloak class="sales-pocketbook-dialog-backdrop" @keydown.escape.window="if (!conflictDialogOpen()) closeLeadModal()">
        <div x-ref="leadDialog" role="dialog" aria-modal="true" aria-labelledby="lead-dialog-title" aria-describedby="lead-dialog-description" class="sales-pocketbook-dialog sales-pocketbook-dialog--lead" :aria-busy="leadSaving" @click.outside="if (!conflictDialogOpen()) closeLeadModal()" @keydown.tab="trapFocus($event, $refs.leadDialog)">
            <div class="sales-pocketbook-dialog-header"><div><h2 id="lead-dialog-title">Edit Lead</h2><p id="lead-dialog-description">Perbarui data lead. Progres yang sudah tercatat tidak berubah.</p></div><button type="button" class="sales-pocketbook-dialog-close" aria-label="Tutup dialog edit lead" :disabled="leadSaving" @click="closeLeadModal()">&times;</button></div>
            <template x-if="leadModalOpen"><div x-data="crmPresence(@js(['enabled' => config('presence.enabled', true), 'heartbeatUrl' => route('presence.heartbeat'), 'indexUrl' => route('presence.index'), 'destroyUrl' => route('presence.destroy'), 'heartbeatSeconds' => config('presence.heartbeat_seconds', 25), 'pageKey' => 'sales-pocketbook', 'recordType' => 'sales_lead', 'mode' => 'editing']))" x-init="updateContext({ branchId: edit.branch_id, recordType: 'sales_lead', recordId: edit.id, mode: 'editing' })" x-show="others.length" class="mb-3 border-2 border-black bg-[#eef1ff] p-2 text-xs"><strong x-text="summary"></strong></div></template>
            <div x-ref="leadValidationAlert" x-show="leadValidationError" x-text="leadValidationError" class="sales-pocketbook-dialog-error" role="alert" aria-live="assertive" tabindex="-1"></div>
            <form data-conflict-form @submit.prevent="saveLeadEdit($event)" class="sales-pocketbook-dialog-form" :aria-busy="leadSaving">
                <input type="hidden" name="expected_updated_at" x-model="edit.token"><input type="hidden" name="presence_session_key">
                <x-crm.field label="Tanggal Lead" for="modal-lead-date" required><x-crm.date-field id="modal-lead-date" name="lead_date" x-ref="leadEditDate" x-model="edit.lead_date" required /></x-crm.field>
                <x-crm.field label="Nama Calon Konsumen" for="modal-lead-name" required><input id="modal-lead-name" x-ref="leadEditName" class="sales-input" name="customer_name" x-model="edit.customer_name" required></x-crm.field>
                <x-crm.field label="No. WhatsApp / Telepon" for="modal-lead-phone" hint="Peringatan duplikat tidak mencegah penyimpanan."><input id="modal-lead-phone" class="sales-input" name="phone" x-model="edit.phone" @blur="checkPhone($event.target.value, edit.id)" x-bind:aria-busy="duplicatePending" aria-describedby="modal-lead-phone-hint modal-lead-duplicate-status"><div id="modal-lead-duplicate-status" class="sales-pocketbook-duplicate-status" aria-live="polite" aria-atomic="true"><p x-show="duplicatePending" x-cloak>Memeriksa nomor duplikat...</p><div x-show="!duplicatePending && duplicates.length" x-cloak class="sales-pocketbook-duplicate-result"><strong>Peringatan duplikat, lead tetap dapat disimpan.</strong><template x-for="item in duplicates" :key="item.id"><div x-text="`${item.sales} / ${item.branch} / ${item.project} / ${item.date}`"></div></template></div></div></x-crm.field>
                <x-crm.field label="Sumber Lead" for="modal-lead-source" required><select id="modal-lead-source" class="sales-input" name="source" x-model="edit.source" required><option value="">Pilih sumber</option><template x-for="option in leadOptions.source" :key="option"><option :value="option" x-text="option"></option></template></select><p x-show="edit.historical_source" x-cloak class="mt-1 text-xs text-amber-800">Nilai sebelumnya “<span x-text="edit.historical_source"></span>” tidak tersedia lagi. Pilih sumber aktif sebelum menyimpan.</p></x-crm.field>
                <x-crm.field label="Kanal Masuk" for="modal-lead-platform" required><select id="modal-lead-platform" class="sales-input" name="platform" x-model="edit.platform" required><template x-for="option in leadOptions.channel" :key="option"><option :value="option" x-text="option"></option></template></select></x-crm.field>
                <x-crm.field label="Aktivitas Lead" for="modal-lead-campaign" required><select id="modal-lead-campaign" class="sales-input" name="campaign_name" x-model="edit.campaign_name" required><template x-for="option in leadOptions.activity" :key="option"><option :value="option" x-text="option"></option></template></select></x-crm.field>
                <x-crm.field label="Nama Promo" for="modal-lead-promo"><select id="modal-lead-promo" class="sales-input" name="id_promo" x-model="edit.id_promo"><option value="">Pilih promo (opsional)</option><template x-for="option in leadOptions.promo" :key="option"><option :value="option" x-text="option"></option></template></select></x-crm.field>
                <template x-if="edit.status_manual"><x-crm.field label="Status Lead" for="modal-lead-status"><select id="modal-lead-status" class="sales-input" name="current_status" x-model="edit.current_status"><option value="no_response">No Respon</option><option value="discussion">Diskusi</option><option value="site_visit">Cek Lokasi</option></select></x-crm.field></template><template x-if="!edit.status_manual"><div class="crm-field"><span id="modal-lead-status-label" class="crm-field-label">Status Lead</span><div class="crm-field-control"><span class="block border border-gray-400 bg-gray-100 px-3 py-2 text-sm font-bold" aria-labelledby="modal-lead-status-label" x-text="`${edit.current_status_label} (status sistem, baca-saja)`"></span></div></div></template>
                <x-crm.field label="Cabang" for="modal-lead-branch" required><select id="modal-lead-branch" class="sales-input" name="branch_id" x-model="edit.branch_id" @change="editBranchChanged()" required>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></x-crm.field>
                <x-crm.field label="Proyek" for="modal-lead-project" required><select id="modal-lead-project" class="sales-input" name="project_id" x-model="edit.project_id" @change="editProjectChanged()" required>@foreach($projects as $project)<option value="{{ $project->id }}" x-show="editProjectVisible('{{ $project->id }}')" :disabled="!editProjectVisible('{{ $project->id }}')">{{ $project->project_name }}</option>@endforeach</select></x-crm.field>
                <x-crm.field label="Sales" for="modal-lead-sales" required><select id="modal-lead-sales" class="sales-input" name="sales_user_id" x-model="edit.sales_user_id" required @disabled(Auth::user()->isSales())>@foreach($salesUsers as $sales)<option value="{{ $sales->id }}" x-show="editSalesVisible('{{ $sales->id }}')" :disabled="!editSalesVisible('{{ $sales->id }}')">{{ $sales->name }}</option>@endforeach</select></x-crm.field>
                <x-crm.field label="Referensi Konsumen Tertaut" for="modal-lead-reference"><input id="modal-lead-reference" class="sales-input" name="linked_consumer_reference" x-model="edit.linked_consumer_reference"></x-crm.field>
                <x-crm.field label="Catatan" for="modal-lead-notes" class="md:col-span-2"><textarea id="modal-lead-notes" class="sales-input" name="notes" rows="3" x-model="edit.notes"></textarea></x-crm.field>
                <div class="sales-pocketbook-dialog-actions md:col-span-2"><x-crm.button type="submit" variant="primary" accent="sales" x-bind:disabled="leadSaving" x-bind:aria-busy="leadSaving"><span x-text="leadSaving ? 'Menyimpan...' : 'Simpan Perubahan'"></span></x-crm.button><x-crm.button type="button" variant="secondary" x-bind:disabled="leadSaving" @click="closeLeadModal()">Batal</x-crm.button><a :href="edit.fallback_url" :aria-disabled="leadSaving" :tabindex="leadSaving ? -1 : 0" @click="if (leadSaving) $event.preventDefault()" class="crm-button crm-button--secondary crm-button--md">Buka Halaman Edit</a><span class="sr-only" aria-live="polite" x-text="leadSaving ? 'Perubahan lead sedang disimpan.' : ''"></span></div>
            </form>
        </div>
    </div>

    <div x-show="stageModalOpen" x-cloak class="sales-pocketbook-dialog-backdrop" @keydown.escape.window="if (!conflictDialogOpen()) closeStageModal()">
        <div x-ref="stageDialog" role="dialog" aria-modal="true" aria-labelledby="stage-dialog-title" aria-describedby="stage-dialog-description" class="sales-pocketbook-dialog sales-pocketbook-dialog--stage" :aria-busy="stageSaving" @click.outside="if (!conflictDialogOpen()) closeStageModal()" @keydown.tab="trapFocus($event, $refs.stageDialog)">
            <div class="sales-pocketbook-dialog-header"><div><h2 id="stage-dialog-title" x-text="stageEdit.reverse ? 'Batalkan Tahap' : 'Catat Tahap'"></h2><p id="stage-dialog-description">Konfirmasi tanggal dan waktu progres lead.</p></div><button type="button" class="sales-pocketbook-dialog-close" aria-label="Tutup dialog tahapan lead" @click="closeStageModal()">&times;</button></div>
            <div x-ref="stageValidationAlert" x-show="stageValidationError" x-text="stageValidationError" class="sales-pocketbook-dialog-error" role="alert" aria-live="assertive" tabindex="-1"></div>
            <form x-ref="stageForm" data-conflict-form @submit.prevent="saveStage()" :aria-busy="stageSaving">
                <p class="mb-3 text-sm"><strong x-text="stageEdit.label"></strong><span x-show="stageEdit.current" class="block text-xs" x-text="`Nilai saat ini: ${stageEdit.currentLabel}`"></span></p>
                <template x-if="!stageEdit.reverse"><div class="space-y-3"><x-crm.field label="Tanggal" for="stage-edit-date" required><x-crm.date-field id="stage-edit-date" name="stage_date" x-ref="stageDate" x-model="stageEdit.date" required /></x-crm.field><x-crm.field label="Jam" for="stage-edit-time" required><x-crm.time-field id="stage-edit-time" name="stage_time" x-ref="stageTime" x-model="stageEdit.time" required /></x-crm.field><label x-show="stageEdit.current" class="sales-pocketbook-dialog-confirm"><input type="checkbox" x-model="stageEdit.confirmed"> <span>Timpa waktu tahap yang sudah tersimpan.</span></label></div></template>
                <template x-if="stageEdit.reverse"><label class="sales-pocketbook-dialog-confirm sales-pocketbook-dialog-confirm--danger"><input type="checkbox" x-model="stageEdit.confirmed"> <span>Batalkan tahap ini dan seluruh tahap setelahnya.</span></label></template>
                <div class="sales-pocketbook-dialog-actions"><x-crm.button type="submit" variant="primary" accent="sales" x-bind:disabled="stageSaving || ((stageEdit.current || stageEdit.reverse) && !stageEdit.confirmed)" x-bind:aria-busy="stageSaving"><span x-text="stageSaving ? 'Menyimpan...' : 'Simpan'"></span></x-crm.button><x-crm.button type="button" variant="secondary" @click="closeStageModal()">Batal</x-crm.button><span class="sr-only" aria-live="polite" x-text="stageSaving ? 'Tahap lead sedang disimpan.' : ''"></span></div>
            </form>
        </div>
    </div>
</div>

<script>
window.salesPocketbook = function salesPocketbook() {
    const projects = @js($cascadeProjects);
    const salesUsers = @js($cascadeSales);
    const leadStages = @js(array_keys(\App\Models\SalesLead::STAGES));
    const leadStageLabels = @js(\App\Models\SalesLead::STAGES);
    const localParts = value => {
        const date = value ? new Date(value.replace(' ', 'T')) : new Date()
        return {
            date: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`,
            time: `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`,
        }
    }
    return {
        duplicates: [], duplicatePending: false, phoneController: null, phoneRequestId: 0,
        leadModalOpen: false, leadSaving: false, leadValidationError: '', leadTrigger: null, edit: {}, leadCache: {}, leadTokens: {}, leadOptions: { promo: [], source: [], channel: [], activity: [] }, syncUpdateAvailable: false,
        stageModalOpen: false, stageSaving: false, stageValidationError: '', stageTrigger: null, stageEdit: {},
        async checkPhone(phone, exceptId = null) {
            this.phoneController?.abort()
            const requestId = ++this.phoneRequestId
            if (!phone) { this.duplicates = []; this.duplicatePending = false; return }
            const controller = new AbortController()
            this.phoneController = controller
            this.duplicatePending = true
            try {
                const url = new URL(@json(route('sales-leads.duplicate-phone')), window.location.origin)
                url.searchParams.set('phone', phone)
                if (exceptId) url.searchParams.set('except_id', exceptId)
                const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'application/json' } })
                if (!response.ok) throw new Error()
                const matches = (await response.json()).matches
                if (requestId === this.phoneRequestId) this.duplicates = matches
            } catch (error) {
                if (error.name === 'AbortError' || requestId !== this.phoneRequestId) return
                this.duplicates = []
                window.oasisToast('Pemeriksaan nomor duplikat belum tersedia. Data tetap dapat disimpan.', 'warning')
            } finally {
                if (requestId === this.phoneRequestId) {
                    this.duplicatePending = false
                    this.phoneController = null
                }
            }
        },
        openLeadEdit(lead) {
            if (this.leadSaving) return
            this.leadTrigger = document.activeElement
            this.phoneController?.abort()
            this.phoneRequestId++
            this.duplicatePending = false
            this.edit = structuredClone(this.leadCache[lead.id] || lead)
            this.edit.token = this.leadTokens[lead.id] || this.edit.token
            this.leadValidationError = ''
            this.duplicates = []
            this.leadModalOpen = true
            this.loadLeadOptions(this.edit.branch_id)
            this.$salesBodyScroll.lock('sales-pocketbook-lead-dialog')
            this.$nextTick(() => {
                this.$refs.leadEditDate?.dispatchEvent(new Event('input', { bubbles: true }))
                this.$refs.leadEditName?.focus()
            })
        },
        closeLeadModal(force = false) {
            if (!this.leadModalOpen || (this.leadSaving && !force)) return
            this.leadModalOpen = false
            this.phoneController?.abort()
            this.duplicatePending = false
            this.duplicates = []
            this.$salesBodyScroll.unlock('sales-pocketbook-lead-dialog')
            this.$nextTick(() => this.leadTrigger?.focus())
        },
        editProjectVisible(id) { return projects.find(item => item.id === String(id))?.branch_id === String(this.edit.branch_id) },
        editSalesVisible(id) {
            const sales = salesUsers.find(item => item.id === String(id))
            return Boolean(sales && (!this.edit.project_id || sales.project_ids.includes(String(this.edit.project_id))))
        },
        editBranchChanged() {
            if (!this.editProjectVisible(this.edit.project_id)) this.edit.project_id = ''
            if (!this.editSalesVisible(this.edit.sales_user_id)) this.edit.sales_user_id = ''
            this.loadLeadOptions(this.edit.branch_id)
        },
        async loadLeadOptions(branchId) {
            try {
                const response = await fetch(@json($leadOptionsEndpoint).replace('BRANCH_ID', branchId), { headers: { Accept: 'application/json' } })
                if (!response.ok) throw new Error()
                this.leadOptions = (await response.json()).options
                if (this.edit.source && !this.leadOptions.source.includes(this.edit.source)) {
                    this.edit.historical_source = this.edit.source
                    this.edit.source = ''
                } else this.edit.historical_source = ''
            } catch (_) { this.leadValidationError = 'Pilihan spreadsheet cabang belum dapat dimuat.' }
        },
        editProjectChanged() {
            const previousBranch = this.edit.branch_id
            const selected = projects.find(item => item.id === String(this.edit.project_id))
            if (selected) this.edit.branch_id = selected.branch_id
            if (!this.editSalesVisible(this.edit.sales_user_id)) this.edit.sales_user_id = ''
            if (this.edit.branch_id !== previousBranch) this.loadLeadOptions(this.edit.branch_id)
        },
        handleLifecycleSync(detail) {
            if (detail?.module_key !== 'sales-lead-lifecycle' || String(detail?.scope?.id || '') !== String(@json($syncBranch?->id))) return
            if (!['success', 'partial_success'].includes(detail.status)) return
            const quickForm = document.querySelector('#quick-lead-input form')
            const quickDraft = quickForm && [...quickForm.elements].some(field => field.name && !['operation_uuid', '_token', 'lead_date', 'branch_id', 'project_id', 'sales_user_id', 'current_status'].includes(field.name) && String(field.value || '').trim() !== '')
            const draftActive = this.leadModalOpen || this.stageModalOpen || Boolean(document.querySelector('[role="dialog"]:not([style*="display: none"])')) || quickDraft
            if (draftActive) this.syncUpdateAvailable = true
            else window.location.reload()
        },
        conflictDialogOpen() { return document.documentElement.dataset.oasisConflictOpen === '1' },
        async responseData(response) {
            const text = await response.text()
            try { return text ? JSON.parse(text) : {} } catch (_) { return { message: response.ok ? '' : `Server mengembalikan respons tidak valid (${response.status}).` } }
        },
        async saveLeadEdit(event) {
            this.leadSaving = true
            this.leadValidationError = ''
            try {
                const response = await fetch(this.edit.url, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                    body: JSON.stringify({
                        branch_id: this.edit.branch_id, project_id: this.edit.project_id, sales_user_id: this.edit.sales_user_id,
                        lead_date: this.edit.lead_date, customer_name: this.edit.customer_name,
                        phone: this.edit.phone, source: this.edit.source, platform: this.edit.platform, campaign_name: this.edit.campaign_name,
                        id_promo: this.edit.id_promo, current_status: this.edit.status_manual ? this.edit.current_status : null,
                        notes: this.edit.notes, linked_consumer_reference: this.edit.linked_consumer_reference,
                        expected_updated_at: this.edit.token, presence_session_key: event.currentTarget.elements.presence_session_key?.value || null,
                    }),
                })
                const data = await this.responseData(response)
                if (response.status === 409) {
                    window.dispatchEvent(new CustomEvent('oasis-conflict', { detail: { response: data, context: { form: event.currentTarget } } }))
                    return
                }
                if (!response.ok) {
                    const message = Object.values(data.errors || {})[0]?.[0] || data.message || 'Perubahan gagal disimpan.'
                    if (response.status === 422) {
                        this.leadValidationError = message
                        this.$nextTick(() => this.$refs.leadValidationAlert?.focus())
                    }
                    else window.oasisToast(message, 'error')
                    return
                }
                this.edit.token = data.updated_at
                this.edit.source = data.lead.source
                this.edit.platform = data.lead.platform
                this.edit.campaign_name = data.lead.campaign_name
                this.edit.id_promo = data.lead.id_promo
                this.edit.current_status = data.lead.current_status
                this.edit.current_status_label = data.lead.current_status_label
                this.leadTokens[this.edit.id] = data.updated_at
                this.leadCache[this.edit.id] = structuredClone(this.edit)
                document.querySelectorAll(`[data-lead-id="${this.edit.id}"]`).forEach(group => { group.dataset.token = data.updated_at })
                document.querySelectorAll(`[data-lead-row="${this.edit.id}"], [data-lead-card="${this.edit.id}"]`).forEach(container => {
                    const values = { name: data.lead.customer_name, phone: data.lead.phone || '—', project: data.lead.project, assignment: `${data.lead.branch} / ${data.lead.sales}`, source: data.lead.source, date: data.lead.lead_date.split('-').reverse().join('/') }
                    Object.entries(values).forEach(([field, value]) => container.querySelector(`[data-lead-field="${field}"]`)?.replaceChildren(document.createTextNode(value || '—')))
                })
                const activityTime = document.querySelector(`[data-lead-activity-time="${this.edit.id}"][data-activity-stage=""]`)
                if (activityTime) {
                    activityTime.dataset.leadBaseline = `${data.lead.lead_date.split('-').reverse().join('/')} 00:00`
                    activityTime.textContent = activityTime.dataset.leadBaseline
                }
                document.dispatchEvent(new CustomEvent('oasis-presence-saved'))
                this.closeLeadModal(true)
                if (data.lead.current_status === 'site_visit') this.$nextTick(() => this.$dispatch('oasis:modal-open', { name: this.edit.site_visit_modal }))
                window.oasisToast(data.message || 'Lead berhasil diperbarui.')
            } catch (_) {
                window.oasisToast('Perubahan gagal. Draf tetap tersimpan; periksa koneksi lalu coba lagi.', 'error')
            } finally { this.leadSaving = false }
        },
        stage(event) {
            this.stageTrigger = event.currentTarget
            const button = event.currentTarget
            const controls = button.closest('[data-token]')
            const reverse = button.dataset.reverse === '1'
            const current = button.dataset.current || ''
            const parts = localParts(current)
            this.stageEdit = { button, controls, reverse, current, currentLabel: current ? new Date(current.replace(' ', 'T')).toLocaleString('id-ID') : '', label: button.dataset.label, date: parts.date, time: parts.time, confirmed: false }
            this.stageValidationError = ''
            this.stageModalOpen = true
            this.$salesBodyScroll.lock('sales-pocketbook-stage-dialog')
            this.$nextTick(() => {
                this.$refs.stageDate?.dispatchEvent(new Event('input', { bubbles: true }))
                this.$refs.stageTime?.dispatchEvent(new Event('input', { bubbles: true }))
                this.$refs.stageDialog?.querySelector('.date-display, input, button')?.focus()
            })
        },
        closeStageModal() {
            if (!this.stageModalOpen) return
            this.stageModalOpen = false
            this.$salesBodyScroll.unlock('sales-pocketbook-stage-dialog')
            this.$nextTick(() => this.stageTrigger?.focus())
        },
        trapFocus(event, container) {
            const focusable = [...container.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
                .filter(element => element.offsetParent !== null && !element.matches('input[type="date"], input[type="time"]'))
            if (!focusable.length) return
            const first = focusable[0]
            const last = focusable[focusable.length - 1]
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
        },
        async saveStage() {
            const { button, controls, reverse } = this.stageEdit
            this.stageSaving = true
            this.stageValidationError = ''
            try {
                const response = await fetch(button.dataset.url, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                    body: JSON.stringify({ stage: button.dataset.stage, action: reverse ? 'reverse' : 'set', timestamp: reverse ? null : `${this.stageEdit.date} ${this.stageEdit.time}`, reversal_confirmed: reverse ? 1 : null, expected_updated_at: controls.dataset.token }),
                })
                const data = await this.responseData(response)
                if (response.status === 409) {
                    window.dispatchEvent(new CustomEvent('oasis-conflict', { detail: { response: data, context: { form: this.$refs.stageForm } } }))
                    return
                }
                if (!response.ok) {
                    const message = Object.values(data.errors || {})[0]?.[0] || data.message || 'Perubahan gagal.'
                    if (response.status === 422) {
                        this.stageValidationError = message
                        this.$nextTick(() => this.$refs.stageValidationAlert?.focus())
                    }
                    else window.oasisToast(message, 'error')
                    return
                }

                const currentStage = [...leadStages].reverse().find(stage => data.stages[stage]) || ''
                const currentActivity = currentStage ? new Date(data.stages[currentStage]) : null
                document.querySelectorAll(`[data-stage-label="${controls.dataset.leadId}"]`).forEach(el => {
                    el.textContent = data.current_stage_label
                    el.classList.remove('crm-status-badge--pending', 'crm-status-badge--processing', 'crm-status-badge--success')
                    el.classList.add(currentStage === 'akad_at' ? 'crm-status-badge--success' : (currentStage ? 'crm-status-badge--processing' : 'crm-status-badge--pending'))
                })
                document.querySelectorAll(`[data-lead-activity-label="${controls.dataset.leadId}"]`).forEach(el => { el.textContent = currentStage ? `Tahap ${leadStageLabels[currentStage]}` : 'Lead dicatat' })
                document.querySelectorAll(`[data-lead-activity-time="${controls.dataset.leadId}"]`).forEach(el => {
                    el.dataset.activityStage = currentStage
                    el.textContent = currentActivity
                        ? currentActivity.toLocaleString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).replace(',', '')
                        : el.dataset.leadBaseline
                })
                document.querySelectorAll(`[data-lead-id="${controls.dataset.leadId}"]`).forEach(group => {
                    group.dataset.token = data.updated_at
                    group.querySelectorAll('[data-stage-kind="value"]').forEach(stageButton => {
                        const completed = Boolean(data.stages[stageButton.dataset.stage])
                        stageButton.classList.toggle('done', completed)
                        stageButton.dataset.current = data.stages[stageButton.dataset.stage] || ''
                        stageButton.querySelector('span').textContent = completed ? 'Tercatat' : 'Catat'
                        stageButton.setAttribute('aria-label', `${completed ? 'Ubah waktu tahap' : 'Catat tahap'} ${stageButton.dataset.label}`)
                    })
                    group.querySelectorAll('[data-stage-kind="reverse"]').forEach(reverseButton => {
                        reverseButton.classList.toggle('hidden', !data.stages[reverseButton.dataset.stage])
                        reverseButton.dataset.current = data.stages[reverseButton.dataset.stage] || ''
                    })
                })
                this.leadTokens[controls.dataset.leadId] = data.updated_at
                if (this.leadCache[controls.dataset.leadId]) this.leadCache[controls.dataset.leadId].token = data.updated_at
                this.closeStageModal()
                window.oasisToast(data.message || (reverse ? 'Tahap lead berhasil dibatalkan.' : 'Tahap lead berhasil diperbarui.'))
            } catch (_) {
                window.oasisToast('Perubahan gagal. Periksa koneksi lalu coba lagi.', 'error')
            } finally { this.stageSaving = false }
        },
    }
};
</script>
@endsection
