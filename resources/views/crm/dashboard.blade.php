@extends('layouts.crm')

@section('title', 'Dashboard - Oasis CRM')

@php
    $user = Auth::user();
    $currentHour = now()->hour;
    $greeting = $currentHour < 11 ? 'Selamat Pagi' : ($currentHour < 15 ? 'Selamat Siang' : ($currentHour < 19 ? 'Selamat Sore' : 'Selamat Malam'));
    $firstName = \Illuminate\Support\Str::before($user->name, ' ');
    $workspaceName = isset($branch) && $branch ? $branch->name : ($user->isSuperadmin() ? 'Semua Cabang' : 'Belum ada cabang');
    $currentDate = now()->locale('id')->isoFormat('dddd, D MMMM Y');

    $canViewPlanner = $user->hasScopedPermission('work_planner');
    $canAccessDatabase = $user->hasPermission('database.view') && $user->hasScopedPermission('database');
    $canAccessBridgeFund = $user->hasPermission('bridge_fund.view') && $user->hasScopedPermission('bridge_fund');
    $canAccessConsumerProgress = $user->hasPermission('consumer_progress.view') && $user->hasScopedPermission('consumer_progress');
    $canViewDatabase = $canAccessDatabase && $user->hasAnyPermission(['database.view_branch', 'database.view_all']);
    $canViewBridgeFund = $canAccessBridgeFund && $user->hasAnyPermission(['bridge_fund.view_branch', 'bridge_fund.view_all']);
    $canViewConsumerProgress = $canAccessConsumerProgress && $user->hasAnyPermission(['consumer_progress.view_branch', 'consumer_progress.view_all']);
    $canViewSystemHealth = $user->hasPermission('system_health.view');
    $canUseDashboardProjectFilter = ($selectedBranchId ?? null) && $user->hasAnyPermission([
        'sales_pocketbook.view_branch', 'sales_pocketbook.view_all',
        'work_planner.view_branch', 'work_planner.view_all',
        'database.view_branch', 'database.view_all',
        'consumer_progress.view_branch', 'consumer_progress.view_all',
        'bridge_fund.view_branch', 'bridge_fund.view_all',
        'expenses.view_branch', 'expenses.view_all',
    ]);
    $availableDashboardProjects = $canUseDashboardProjectFilter && isset($projects)
        ? $projects->groupBy('project_name')->filter(fn ($matches) => $matches->count() === 1)->map->first()->values()
        : collect();
    $matchingProjects = ($selectedProject ?? null) && isset($projects)
        ? $projects->where('project_name', $selectedProject)
        : collect();
    $workspaceProject = $canUseDashboardProjectFilter && $matchingProjects->count() === 1
        ? $matchingProjects->first()->project_name
        : null;
    $hasInvalidProjectScope = (bool) ($selectedProject ?? null) && $workspaceProject === null;
    $canPassSalesWorkspace = $user->hasAnyPermission(['sales_pocketbook.view_branch', 'sales_pocketbook.view_all']);

    $monitoringParams = array_filter([
        'tab' => 'report',
        'branch_id' => $canPassSalesWorkspace ? ($selectedBranchId ?? null) : null,
        'project_id' => $canPassSalesWorkspace && $workspaceProject ? $matchingProjects->first()?->id : null,
    ]);
    $quickActions = [];

    if ($user->hasScopedPermission('sales_pocketbook')) {
        $quickActions[] = ['label' => 'Monitoring Buku Saku', 'url' => route('sales-pocketbook.index', $monitoringParams), 'accent' => 'sales'];
    }
    if ($canAccessDatabase && $user->hasPermission('database.edit') && $user->hasScopedPermission('database', 'manage')) {
        $quickActions[] = ['label' => 'Tambah Lead', 'url' => route('database.index', ['sheet' => 'lead', 'add' => 1]), 'accent' => 'sales'];
    }
    $canCreatePlanner = $canViewPlanner && $user->hasPermission('work_planner.create');
    if ($canCreatePlanner) {
        $quickActions[] = ['label' => 'Tambah Agenda', 'url' => route('content-calendar.create', ['type' => 'agenda']), 'accent' => 'planner'];
    }
    if ($user->can('create', \App\Models\Expense::class)) {
        $quickActions[] = ['label' => 'Tambah Pengeluaran', 'url' => route('expenses.create'), 'accent' => 'expenses'];
    }
    if ($canAccessBridgeFund && $user->hasPermission('bridge_fund.manage') && $user->hasScopedPermission('bridge_fund', 'manage')) {
        $quickActions[] = ['label' => 'Tambah Dana Talangan', 'url' => route('dana-talangan.create'), 'accent' => 'bridge-fund'];
    }
    if ($user->can('create', \App\Models\User::class)) {
        $quickActions[] = ['label' => 'Tambah User', 'url' => route('admin-users.create'), 'accent' => 'administration'];
    }
    if ($canCreatePlanner) {
        $quickActions[] = ['label' => 'Tambah Task', 'url' => route('content-calendar.create', ['type' => 'task']), 'accent' => 'planner'];
    }
    if ($user->isSuperadmin() && $user->hasPermission('projects.manage')) {
        $quickActions[] = ['label' => 'Tambah Proyek', 'url' => route('projects.create'), 'accent' => 'administration'];
    }

    $canOpenQueueItem = function (array $item) use ($canViewPlanner, $canViewDatabase, $canViewBridgeFund): bool {
        return match (true) {
            str_starts_with($item['type'], 'dana_') => $canViewBridgeFund,
            str_starts_with($item['type'], 'task_'), str_starts_with($item['type'], 'agenda_') => $canViewPlanner,
            $item['type'] === 'lead_today' => $canViewDatabase,
            default => false,
        };
    };
    $visibleQueue = collect($actionQueue ?? [])->filter($canOpenQueueItem)->values();
    $attentionItems = $visibleQueue->where('urgency', '<=', 3)->values();
    $todayItems = $visibleQueue->where('urgency', '>', 3)->values();
    $visibleActivity = collect($recentActivity ?? [])->filter(function (array $item) use ($canViewPlanner, $canViewDatabase, $canViewBridgeFund): bool {
        return match ($item['type']) {
            'Lead' => $canViewDatabase,
            'Dana Talangan' => $canViewBridgeFund,
            default => $canViewPlanner,
        };
    })->values();
    $typeLabels = [
        'dana_overdue' => 'Dana Talangan',
        'dana_confirm' => 'Konfirmasi Dana',
        'task_overdue' => 'Task',
        'agenda_overdue' => 'Agenda',
        'task_today' => 'Task',
        'agenda_today' => 'Agenda',
        'lead_today' => 'Lead',
    ];
@endphp

@section('page-title')
    {{ $greeting }}, {{ $firstName }}
@endsection

@section('page-description')
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span class="font-bold text-[var(--oasis-text)]">{{ $workspaceName }}</span>
        @if($workspaceProject)
            <span aria-hidden="true">/</span>
            <span>{{ $workspaceProject }}</span>
        @endif
        <span aria-hidden="true">/</span>
        <span>{{ $currentDate }}</span>
    </div>
@endsection

@section('page-actions')
    <x-dashboard.quick-actions :actions="$quickActions" />
@endsection

@section('toolbar')
    @if((isset($branches) && $branches->count() > 1) || (isset($projects) && $projects->isNotEmpty()))
        <form method="GET" action="{{ route('dashboard') }}" class="dashboard-scope-toolbar" aria-label="Pilih area kerja Dashboard">
            @if(isset($branches) && $branches->count() > 1)
                <div class="dashboard-scope-field">
                    <label for="dashboard-branch" class="dashboard-scope-label">Cabang</label>
                    <select id="dashboard-branch" name="branch_id" class="dashboard-scope-select" onchange="if (this.form.elements.project_name) this.form.elements.project_name.value = ''; this.form.submit()">
                        @if($user->isSuperadmin())
                            <option value="">Semua Cabang</option>
                        @endif
                        @foreach($branches as $availableBranch)
                            <option value="{{ $availableBranch->id }}" @selected((string) ($selectedBranchId ?? '') === (string) $availableBranch->id)>{{ $availableBranch->name }}</option>
                        @endforeach
                    </select>
                </div>
            @elseif(isset($selectedBranchId) && $selectedBranchId)
                <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">
            @endif

            @if($canUseDashboardProjectFilter && $availableDashboardProjects->isNotEmpty())
                <div class="dashboard-scope-field">
                    <label for="dashboard-project" class="dashboard-scope-label">Proyek</label>
                    <select id="dashboard-project" name="project_name" class="dashboard-scope-select" onchange="this.form.submit()">
                        <option value="">Semua Proyek</option>
                        @foreach($availableDashboardProjects as $project)
                            <option value="{{ $project->project_name }}" @selected(($selectedProject ?? null) === $project->project_name)>{{ $project->project_name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if(($selectedProject ?? null) || ($user->isSuperadmin() && ($selectedBranchId ?? null)))
                <a href="{{ route('dashboard') }}" class="inline-flex min-h-11 items-center px-2 py-2 font-[Helvetica] text-xs font-bold text-[var(--oasis-info)] underline">Reset area kerja</a>
            @endif
        </form>
    @endif
@endsection

@section('content')
    <x-crm.page-presence page-key="dashboard" :branch-id="$selectedBranchId ?? null" />

    @if($hasInvalidProjectScope)
        <x-dashboard.section id="dashboard-scope-warning" eyebrow="Area kerja tidak valid" title="Pilih ulang proyek Dashboard" description="Filter proyek tidak dapat diterapkan dengan aman pada cabang ini. Data operasional disembunyikan sampai area kerja direset.">
            <x-crm.button variant="text" :href="route('dashboard', array_filter(['branch_id' => $selectedBranchId ?? null]))">Reset filter proyek</x-crm.button>
        </x-dashboard.section>
    @else
    @if($canViewDatabase || $canViewBridgeFund)
        <section id="dashboard-kpis" aria-labelledby="dashboard-kpis-title" class="mb-5">
            <div class="mb-2">
                <div class="dashboard-section-eyebrow">Apa yang terjadi</div>
                <h2 id="dashboard-kpis-title" class="dashboard-section-title">Ringkasan Area Kerja</h2>
            </div>
            <div class="dashboard-kpi-grid">
                @if($canViewDatabase && isset($leadStats))
                    <x-dashboard.kpi-card label="Lead Hari Ini" :value="$leadStats['leadToday']" context="Input pada tanggal berjalan" accent="sales" />
                    <x-dashboard.kpi-card label="Lead Bulan Ini" :value="$leadStats['leadThisMonth']" context="Akumulasi bulan berjalan" accent="sales" />
                    <x-dashboard.kpi-card label="Sumber Teratas" :value="$leadStats['topSource']" context="Sumber lead paling dominan" accent="info" />
                    <x-dashboard.kpi-card label="Lead Terbaru" :value="$leadStats['latestLeads']->first()['nama'] ?? '—'" :context="$leadStats['latestLeads']->first()['source'] ?? 'Belum ada lead'" accent="info" />
                @endif
                @if($canViewBridgeFund && isset($danaStats))
                    <x-dashboard.kpi-card label="Tidak Sanggup" :value="$danaStats['tidakSanggup']" context="Status Dana Talangan" accent="danger" />
                    <x-dashboard.kpi-card label="Belum Konfirmasi" :value="$danaStats['belumKonfirmasi']" context="Perlu konfirmasi keuangan" accent="warning" />
                    <x-dashboard.kpi-card label="Komitmen Hari Ini" :value="$danaStats['hariIni']" context="Jatuh pada hari ini" accent="info" />
                    <x-dashboard.kpi-card label="Komitmen Overdue" :value="$danaStats['overdue']" context="Belum lunas dan melewati tanggal" accent="danger" />
                @endif
            </div>
        </section>
    @endif

    <div class="dashboard-grid dashboard-grid-two mb-4">
        <x-dashboard.section id="dashboard-attention" eyebrow="Prioritas tertinggi" title="Attention Center" description="Pekerjaan lewat waktu dan konfirmasi yang perlu segera ditangani.">
            <x-slot:actions>
                <x-crm.status-badge :variant="$attentionItems->isEmpty() ? 'success' : 'warning'">{{ $attentionItems->count() }} item</x-crm.status-badge>
            </x-slot:actions>
            @if($attentionItems->isEmpty())
                <x-crm.empty-state title="Semua pekerjaan sudah terkendali" description="Tidak ada pekerjaan mendesak pada area kerja saat ini." />
            @else
                <div class="dashboard-attention-list">
                    @foreach($attentionItems as $item)
                        <article class="dashboard-attention-item" data-critical="{{ $item['urgency'] <= 2 ? 'true' : 'false' }}">
                            <span class="dashboard-attention-status" aria-label="Perlu tindakan">!</span>
                            <div class="min-w-0">
                                <div class="dashboard-attention-type">{{ $typeLabels[$item['type']] ?? $item['type'] }}</div>
                                <div class="dashboard-attention-text">{{ $item['text'] }}</div>
                            </div>
                            <a href="{{ $item['link'] }}" class="dashboard-attention-link">Buka modul</a>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-dashboard.section>

        <x-dashboard.section id="dashboard-today" eyebrow="Apa yang harus dilakukan" title="Pekerjaan Hari Ini" description="Agenda, task, dan lead baru yang sudah masuk antrean hari ini.">
            @if($todayItems->isEmpty())
                <x-crm.empty-state title="Tidak ada pekerjaan terjadwal" description="Gunakan aksi cepat untuk menambahkan agenda atau task berikutnya." />
            @else
                <div class="dashboard-work-list">
                    @foreach($todayItems as $item)
                        <div class="dashboard-work-item">
                            <div class="dashboard-work-meta">{{ $typeLabels[$item['type']] ?? $item['type'] }}</div>
                            <div class="min-w-0">
                                <a href="{{ $item['link'] }}" class="dashboard-work-text hover:underline">{{ $item['text'] }}</a>
                                @if($item['time'])
                                    <div class="mt-1 text-xs text-[var(--oasis-text-muted)]">{{ $item['time']->diffForHumans() }}</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-dashboard.section>
    </div>

    <x-dashboard.section id="dashboard-recent-activity" eyebrow="Apa yang baru terjadi" title="Aktivitas Operasional Terbaru" description="Catatan pembuatan Work Planner, lead Database, dan Dana Talangan pada area kerja ini." class="mb-4">
        <div>
            <x-dashboard.timeline :items="$visibleActivity" />
        </div>
    </x-dashboard.section>

    @if(!$workspaceProject && $canViewConsumerProgress && isset($konsumenProgress) && count($konsumenProgress) > 0)
        @php
            $maxProgressCount = max(array_column($konsumenProgress, 'count'));
        @endphp
        <x-dashboard.section id="dashboard-analytics" eyebrow="Perkembangan operasional" title="Konsumen Progress" description="Perbandingan volume antar tahap berdasarkan cache Konsumen Progress; bukan persentase konversi." class="mb-4">
            <div class="dashboard-progress-list">
                @foreach($konsumenProgress as $stage)
                    @php
                        $progressWidth = $maxProgressCount > 0 ? round($stage['count'] / $maxProgressCount * 100) : 0;
                    @endphp
                    <div>
                        <div class="dashboard-progress-meta"><span>{{ $stage['label'] }}</span><span>{{ $stage['count'] }}</span></div>
                        <div class="dashboard-progress-track" role="progressbar" aria-label="{{ $stage['label'] }}" aria-valuenow="{{ $stage['count'] }}" aria-valuemin="0" aria-valuemax="{{ max($maxProgressCount, 1) }}">
                            <div class="dashboard-progress-bar" style="width: {{ $progressWidth }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-dashboard.section>
    @endif

    @if(($canViewDatabase && isset($syncHealth)) || $canViewSystemHealth)
        @php
            $databaseState = !isset($syncHealth) || $syncHealth['status'] === 'never'
                ? 'warning'
                : ($syncHealth['status'] === 'success' && !$syncHealth['isStale'] ? 'pass' : ($syncHealth['status'] === 'failed' ? 'fail' : 'warning'));
            $databaseStateLabel = match ($databaseState) { 'pass' => 'PASS', 'fail' => 'FAIL', default => 'WARNING' };
            $databaseBadgeVariant = match ($databaseState) { 'pass' => 'success', 'fail' => 'danger', default => 'warning' };
        @endphp
        <x-dashboard.section id="dashboard-system-status" eyebrow="Kesiapan area kerja" title="Status Data & Sistem" description="Status yang tersedia dari integrasi dan pemeriksaan sistem OASIS saat ini.">
            @if($canViewDatabase && isset($syncHealth))
                <div class="dashboard-status-row">
                    <div>
                        <div class="dashboard-status-label">Google Database Sync</div>
                        <div class="mt-1 text-xs text-[var(--oasis-text-muted)]">{{ $syncHealth['message'] ?? 'Status sinkronisasi belum tersedia.' }}</div>
                    </div>
                    <x-crm.status-badge :variant="$databaseBadgeVariant">{{ $databaseStateLabel }}</x-crm.status-badge>
                </div>
                <div class="mt-3" data-testid="dashboard-database-sync">
                    <x-crm.sync-status-panel module-key="database" :scope-name="$branch?->name ?? ''" :branch-id="$selectedBranchId" :status="$dashboardSyncStatus" :is-stale="$syncHealth['isStale']">
                        <x-crm.sync-control module-key="database" module-name="Sinkronisasi Database" :scope-name="$branch?->name ?? ''" :sync-url="route('database.sync')" :status-url="route('database.sync-status', ['branch_id' => $selectedBranchId])" :status="$dashboardSyncStatus" :branch-id="$selectedBranchId" :can-sync="$canSyncDatabase" button-class="bg-white text-black px-3 py-1.5 text-xs font-[Helvetica] font-bold border border-black" />
                    </x-crm.sync-status-panel>
                </div>
            @endif
            @if($canViewSystemHealth)
                <div class="dashboard-status-row">
                    <div>
                        <div class="dashboard-status-label">System Health</div>
                        <div class="mt-1 text-xs text-[var(--oasis-text-muted)]">Scheduler, notifications, presence, storage, dan layanan aplikasi.</div>
                    </div>
                    <x-crm.button variant="text" :href="route('admin.system-health')">Buka laporan</x-crm.button>
                </div>
            @endif
        </x-dashboard.section>
    @endif
    @endif
@endsection
