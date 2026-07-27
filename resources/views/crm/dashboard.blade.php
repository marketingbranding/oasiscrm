@extends('layouts.crm')

@section('title', 'Dashboard - Oasis CRM')

@section('content')
    @if(Auth::user()->hasRole('sales'))
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
        <a href="{{ route('sales-pocketbook.index', ['input' => 1]) }}" class="border-2 border-black bg-[#fcc20f] px-5 py-4 text-center font-['Arial_Black'] text-lg uppercase shadow-[3px_3px_0_#000]">+ Input Lead Hari Ini</a>
        <a href="{{ route('sales-pocketbook.index', ['tab' => 'agenda']) }}" class="border-2 border-black bg-white px-5 py-4 text-center font-['Arial_Black'] text-lg uppercase shadow-[3px_3px_0_#000]">+ Isi Agenda / Hasil</a>
    </div>
    @elseif(Auth::user()->canViewAllBranches())
    @php
        $monitoringParams = array_filter(['tab' => 'report', 'branch_id' => $selectedBranchId ?? null, 'project_id' => isset($selectedProject) ? optional($projects->firstWhere('project_name', $selectedProject))->id : null]);
    @endphp
    <a href="{{ route('sales-pocketbook.index', $monitoringParams) }}" class="mb-4 inline-block border-2 border-black bg-[#fcc20f] px-4 py-2 font-[Helvetica] text-sm font-bold uppercase shadow-[2px_2px_0_#000]">Monitoring Buku Saku</a>
    @else
    {{-- Quick-Action Dropdown --}}
    <div x-data="{ quickOpen: false }" class="relative mb-4" @click.outside="quickOpen = false">
        <button @click="quickOpen = !quickOpen"
                class="bg-black hover:bg-gray-800 text-white px-3 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none flex items-center gap-2">
            <span class="text-lg leading-none">+</span> Buat Baru
            <span x-show="!quickOpen" class="text-xs">▼</span>
            <span x-show="quickOpen" class="text-xs">▲</span>
        </button>
        <div x-show="quickOpen"
             x-transition.opacity.duration.150ms
             class="absolute left-0 top-full mt-1 bg-white border-2 border-black shadow-xl min-w-[200px] z-50">
            <a href="{{ route('database.index', ['sheet' => 'lead', 'add' => 1]) }}" class="flex items-center gap-3 px-3 py-2 text-sm font-[Helvetica] font-bold border-l-4 border-[#e6915d] hover:bg-gray-50">
                <span>+ Lead Baru</span>
            </a>
            <a href="{{ route('content-calendar.create', ['type' => 'task']) }}" class="flex items-center gap-3 px-3 py-2 text-sm font-[Helvetica] font-bold border-l-4 border-[#9ab6c8] hover:bg-gray-50">
                <span>+ Task Baru</span>
            </a>
            <a href="{{ route('content-calendar.create', ['type' => 'agenda']) }}" class="flex items-center gap-3 px-3 py-2 text-sm font-[Helvetica] font-bold border-l-4 border-[#e6915d] hover:bg-gray-50">
                <span>+ Agenda Baru</span>
            </a>
            <a href="{{ route('content-calendar.create', ['type' => 'content']) }}" class="flex items-center gap-3 px-3 py-2 text-sm font-[Helvetica] font-bold border-l-4 border-[#8c9ae0] hover:bg-gray-50">
                <span>+ Konten Baru</span>
            </a>
            <a href="{{ route('dana-talangan.create') }}" class="flex items-center gap-3 px-3 py-2 text-sm font-[Helvetica] font-bold border-l-4 border-[#f1c40f] hover:bg-gray-50">
                <span>+ Dana Talangan</span>
            </a>
            @if(Auth::user()->isSuperadmin())
                <a href="{{ route('projects.create') }}" class="flex items-center gap-3 px-3 py-2 text-sm font-[Helvetica] font-bold border-l-4 border-[#5d8e8e] hover:bg-gray-50">
                    <span>+ Proyek Baru</span>
                </a>
            @endif
        </div>
    </div>
    @endif

    {{-- Filter Bar --}}
    @if(isset($branches) && $branches->count() > 1)
    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('dashboard') }}" class="flex items-center gap-3 flex-wrap filter-bar">
            @if(isset($branches) && $branches->count() > 0)
            <label class="font-[Helvetica] font-bold text-xs uppercase">Cabang:</label>
            <select name="branch_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Cabang —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }} @if(str_contains(mb_strtolower($b->name), 'pusat')) style="color:#b8860b;font-weight:700;background:#fff3b0" @endif>{{ $b->name }}</option>
                @endforeach
            </select>
            @endif
            @if(isset($projects) && $projects->count() > 0)
            <label class="font-[Helvetica] font-bold text-xs uppercase">Proyek:</label>
            <select name="project_name" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Proyek —</option>
                @foreach($projects as $p)
                    <option value="{{ $p->project_name }}" {{ $selectedProject === $p->project_name ? 'selected' : '' }}>{{ $p->project_name }}</option>
                @endforeach
            </select>
            @endif
        </form>
    </div>
    @elseif(isset($projects) && $projects->count() > 0)
    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('dashboard') }}" class="flex items-center gap-3 flex-wrap filter-bar">
            <label class="font-[Helvetica] font-bold text-xs uppercase">Proyek:</label>
            <select name="project_name" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Proyek —</option>
                @foreach($projects as $p)
                    <option value="{{ $p->project_name }}" {{ $selectedProject === $p->project_name ? 'selected' : '' }}>{{ $p->project_name }}</option>
                @endforeach
            </select>
        </form>
    </div>
    @endif

    {{-- Branch Header --}}
    @if(isset($branch) && $branch)
    <div class="bg-[#8c9ae0] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">{{ $branch->name }}</h1>
    </div>
    @else
    <div class="bg-[#8c9ae0] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Dashboard</h1>
    </div>
    @endif

    <x-crm.page-presence page-key="dashboard" :branch-id="$selectedBranchId" />

    @if(Auth::user()->hasRole('sales') && isset($salesWeekly))
    <div class="mb-4">
        <div class="text-[10px] font-[Helvetica] font-bold uppercase tracking-wider mb-1 text-gray-600">Buku Saku Minggu Ini</div>
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-1.5">
            @foreach(['lead_new' => 'Lead Baru', 'contacted' => 'Dihubungi', 'met' => 'Tatap Muka', 'surveyed' => 'Survey', 'utj' => 'UTJ', 'documents_completed' => 'Berkas', 'akad' => 'Akad', 'agenda_completed' => 'Agenda Selesai'] as $key => $label)
            <div class="border-2 border-black {{ $key === 'agenda_completed' ? 'bg-[#fff3b0]' : 'bg-white' }} px-2 py-2"><div class="font-[Helvetica] font-bold text-[9px] uppercase text-gray-600">{{ $label }}</div><div class="font-['Arial_Black'] text-2xl">{{ $salesWeekly[$key] }}</div></div>
            @endforeach
        </div>
    </div>
    <div class="border-2 border-black mb-4">
        <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Pengingat Operasional</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5">
            @foreach([
                ['show' => $salesReminders['no_agenda_today'], 'count' => null, 'label' => 'Belum ada agenda hari ini', 'tab' => 'agenda'],
                ['show' => $salesReminders['done_without_result'] > 0, 'count' => $salesReminders['done_without_result'], 'label' => 'Agenda selesai tanpa hasil', 'tab' => 'agenda'],
                ['show' => $salesReminders['never_contacted'] > 0, 'count' => $salesReminders['never_contacted'], 'label' => 'Lead belum dihubungi', 'tab' => 'leads'],
                ['show' => $salesReminders['stale_progress'] > 0, 'count' => $salesReminders['stale_progress'], 'label' => 'Lead tanpa progres ≥3 hari', 'tab' => 'leads'],
                ['show' => $salesReminders['duplicate_phone_groups'] > 0, 'count' => $salesReminders['duplicate_phone_groups'], 'label' => 'Nomor telepon duplikat', 'tab' => 'leads'],
            ] as $reminder)
            <a href="{{ route('sales-pocketbook.index', ['tab' => $reminder['tab']]) }}" class="border-t sm:border-r border-black p-3 {{ $reminder['show'] ? 'bg-[#fff3b0]' : 'bg-white text-gray-500' }}"><strong class="font-[Helvetica] text-lg">{{ $reminder['count'] ?? ($reminder['show'] ? '!' : '0') }}</strong><div class="text-xs">{{ $reminder['label'] }}</div></a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- === LEADS KPI === --}}
    @if(isset($leadStats))
    <div class="mb-4">
        <div class="text-[10px] font-[Helvetica] font-bold uppercase tracking-wider mb-1 text-gray-600">LEADS</div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-1.5">
            <div class="border-2 border-black bg-white px-2 py-1.5">
                <div class="font-[Helvetica] font-bold text-[10px] uppercase leading-tight text-gray-500">Hari Ini</div>
                <div class="font-['Arial_Black'] font-black text-2xl leading-tight">{{ $leadStats['leadToday'] }}</div>
            </div>
            <div class="border-2 border-black bg-white px-2 py-1.5">
                <div class="font-[Helvetica] font-bold text-[10px] uppercase leading-tight text-gray-500">Bulan Ini</div>
                <div class="font-['Arial_Black'] font-black text-2xl leading-tight">{{ $leadStats['leadThisMonth'] }}</div>
            </div>
            <div class="border-2 border-black bg-white px-2 py-1.5">
                <div class="font-[Helvetica] font-bold text-[10px] uppercase leading-tight text-gray-500">Sumber Teratas</div>
                <div class="font-['Arial_Black'] font-black text-lg leading-tight truncate">{{ $leadStats['topSource'] }}</div>
            </div>
            <div class="border-2 border-black bg-white px-2 py-1.5">
                <div class="font-[Helvetica] font-bold text-[10px] uppercase leading-tight text-gray-500">Lead Terbaru</div>
                @if($leadStats['latestLeads']->count() > 0)
                    <div class="text-[10px] font-['Times_New_Roman'] leading-tight truncate">{{ $leadStats['latestLeads']->first()['nama'] }} — {{ $leadStats['latestLeads']->first()['source'] }}</div>
                @else
                    <div class="text-[10px] font-['Times_New_Roman'] leading-tight text-gray-500">—</div>
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- === DANA TALANGAN STATUS === --}}
    @if(isset($danaStats))
    <div class="mb-4">
        <div class="text-[10px] font-[Helvetica] font-bold uppercase tracking-wider mb-1 text-gray-600">DANA TALANGAN</div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-1.5">
            <div class="border-2 border-black bg-[#d77a7a] px-2 py-1.5 text-white">
                <div class="font-[Helvetica] font-bold text-[10px] uppercase leading-tight">Tidak Sanggup</div>
                <div class="font-['Arial_Black'] font-black text-2xl leading-tight">{{ $danaStats['tidakSanggup'] }}</div>
            </div>
            <div class="border-2 border-black bg-[#f1c40f] px-2 py-1.5">
                <div class="font-[Helvetica] font-bold text-[10px] uppercase leading-tight">Belum Konfirmasi</div>
                <div class="font-['Arial_Black'] font-black text-2xl leading-tight">{{ $danaStats['belumKonfirmasi'] }}</div>
            </div>
            <div class="border-2 border-black bg-white px-2 py-1.5">
                <div class="font-[Helvetica] font-bold text-[10px] uppercase leading-tight text-gray-500">Komitmen Hari Ini</div>
                <div class="font-['Arial_Black'] font-black text-2xl leading-tight">{{ $danaStats['hariIni'] }}</div>
            </div>
            <div class="border-2 border-black bg-[#d77a7a] px-2 py-1.5 text-white">
                <div class="font-[Helvetica] font-bold text-[10px] uppercase leading-tight">Komitmen Overdue</div>
                <div class="font-['Arial_Black'] font-black text-2xl leading-tight">{{ $danaStats['overdue'] }}</div>
            </div>
        </div>
    </div>
    @endif

    {{-- === COMPACT ACTION QUEUE === --}}
    @if(isset($actionQueue) && $actionQueue->count() > 0)
    <div class="border-2 border-black mb-4">
        <div class="bg-black text-white px-2 py-1 font-[Helvetica] font-bold text-[10px] uppercase">🔔 Action Queue</div>
        @php $typeLabels = ['dana_overdue' => 'Dana', 'dana_confirm' => 'Dana', 'task_overdue' => 'Task', 'task_today' => 'Task', 'agenda_overdue' => 'Agenda', 'agenda_today' => 'Agenda', 'lead_today' => 'Lead']; @endphp
        @foreach($actionQueue as $aq)
        <div class="px-2 py-1 text-xs font-['Times_New_Roman'] border-t border-black flex items-center gap-1.5 hover:bg-gray-50">
            <span class="text-[10px] font-[Helvetica] font-bold uppercase {{ $aq['urgency'] <= 2 ? 'text-[#e91d2a]' : 'text-gray-500' }}">{{ $typeLabels[$aq['type']] ?? $aq['type'] }}</span>
            <a href="{{ $aq['link'] }}" class="font-bold hover:underline truncate block flex-1">{{ $aq['text'] }}</a>
        </div>
        @endforeach
    </div>
    @endif

    {{-- === KONSUMEN PROGRESS PIPELINE === --}}
    @if(isset($konsumenProgress) && count($konsumenProgress) > 0)
    <div class="border-2 border-black mb-4">
        <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Konsumen Progress</div>
        <div class="px-3 py-3">
            @php
                $maxCount = max(array_column($konsumenProgress, 'count'));
            @endphp
            @foreach($konsumenProgress as $stage)
            <div class="mb-2">
                <div class="flex items-center justify-between text-xs font-[Helvetica] font-bold mb-0.5">
                    <span>{{ $stage['label'] }}</span>
                    <span>{{ $stage['count'] }}</span>
                </div>
                <div class="w-full bg-gray-200 border border-black" style="height:16px;">
                    <div class="h-full bg-gray-600" style="width:{{ $maxCount > 0 ? round($stage['count'] / $maxCount * 100) : 0 }}%;"></div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- === COMPACT SYNC HEALTH === --}}
    @if(isset($syncHealth))
    <x-crm.sync-status-panel module-key="database" :scope-name="$branch?->name ?? ''" :branch-id="$selectedBranchId" :status="$dashboardSyncStatus" :is-stale="$syncHealth['isStale']" class="px-2 py-1 mb-4">
        <x-crm.sync-control module-key="database" module-name="Sinkronisasi Database" :scope-name="$branch?->name ?? ''" :sync-url="route('database.sync')" :status-url="route('database.sync-status', ['branch_id' => $selectedBranchId])" :status="$dashboardSyncStatus" :branch-id="$selectedBranchId" :can-sync="$canSyncDatabase" button-class="bg-white text-black px-2 py-0.5 text-[10px] font-[Helvetica] font-bold border border-black" />
    </x-crm.sync-status-panel>
    @endif

    {{-- === ACTIVITY FEED (full width, bottom) === --}}
    @if(isset($recentActivity) && $recentActivity->count() > 0)
    <div class="border-2 border-black">
        <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">⚡ Aktivitas Terbaru</div>
        <div class="divide-y divide-black max-h-60 overflow-y-auto">
            @foreach($recentActivity as $act)
            <div class="px-3 py-2 text-sm font-['Times_New_Roman'] flex items-start gap-2">
                <span style="background:{{ $act['color'] }}; width:8px; height:8px; border-radius:50%; display:inline-block; margin-top:6px;" class="shrink-0"></span>
                <div>
                    <div class="font-bold">{{ $act['text'] }}</div>
                    <div class="text-xs text-gray-500">{{ $act['time']->diffForHumans() }} — {{ $act['user'] }}</div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif
@endsection
