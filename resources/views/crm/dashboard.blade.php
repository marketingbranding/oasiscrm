@extends('layouts.crm')

@section('title', 'Dashboard - Oasis CRM')

@section('content')
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
        @php $typeLabels = ['dana_overdue' => 'Dana', 'dana_confirm' => 'Dana', 'task_overdue' => 'Task', 'task_today' => 'Task', 'lead_today' => 'Lead']; @endphp
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
    <div class="border-2 border-black px-2 py-1 mb-4 flex items-center gap-2 text-xs font-['Times_New_Roman']">
        <span class="text-[10px] font-[Helvetica] font-bold uppercase">🔄 Sync</span>
        @if($syncHealth['isStale'])
            <span class="text-[#e91d2a] font-bold">⚠️ Stale</span>
            <span class="text-gray-500">{{ ($syncHealth['finished_at'] ?? null) ? 'Sync terakhir ' . $syncHealth['finished_at']->diffForHumans() : 'Belum pernah sync' }}</span>
        @else
            <span class="text-[#b3bd95] font-bold">✅ Synced</span>
            <span class="text-gray-500">{{ ($syncHealth['finished_at'] ?? null)?->diffForHumans() }}</span>
        @endif
        @if($syncHealth['message'] ?? null)
            <span class="text-gray-400">· {{ $syncHealth['message'] }}</span>
        @endif
        <form method="POST" action="{{ route('database.sync') }}" class="inline ml-auto">
            @csrf
            @if($selectedBranchId ?? null)
            <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">
            @endif
            <button type="submit" class="bg-white text-black px-2 py-0.5 text-[10px] font-[Helvetica] font-bold border border-black hover:bg-gray-100 cursor-pointer">Sync</button>
        </form>
    </div>
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
