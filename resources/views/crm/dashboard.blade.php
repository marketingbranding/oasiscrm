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
            <a href="{{ route('content-calendar.create') }}" class="flex items-center gap-3 px-3 py-2 text-sm font-[Helvetica] font-bold border-l-4 border-[#b3bd95] hover:bg-gray-50">
                <span>+ Task Baru</span>
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
    @if(Auth::user()->canViewAllBranches())
    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('dashboard') }}" class="flex items-center gap-3 flex-wrap filter-bar">
            @if(isset($branches) && $branches->count() > 0)
            <label class="font-[Helvetica] font-bold text-xs uppercase">Cabang:</label>
            <select name="branch_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Cabang —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
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

    {{-- === ALERT ROW: Overdue + Deadline 7 Hari (prominent) === --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <div class="border-2 border-black">
            <div class="bg-black text-white px-3 py-1.5 font-[Helvetica] font-bold text-xs uppercase tracking-wider">Overdue</div>
            <div class="bg-[#d77a7a] px-5 py-5">
                <span class="font-['Arial_Black'] font-black text-4xl">{{ $overdueCount ?? 0 }}</span>
                <span class="font-[Helvetica] font-bold text-sm ml-2 uppercase tracking-wider">Lewat Deadline</span>
            </div>
        </div>
        <div class="border-2 border-black">
            <div class="bg-black text-white px-3 py-1.5 font-[Helvetica] font-bold text-xs uppercase tracking-wider">Deadline 7 Hari</div>
            <div class="bg-[#e6915d] px-5 py-5">
                <span class="font-['Arial_Black'] font-black text-4xl">{{ $upcomingWeekCount ?? 0 }}</span>
                <span class="font-[Helvetica] font-bold text-sm ml-2 uppercase tracking-wider">Segera</span>
            </div>
        </div>
    </div>

    {{-- === HEALTH ROW: Total + Completed + % === --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="border-2 border-black">
            <div class="bg-white border-b-2 border-black px-3 py-1.5">
                <span class="font-[Helvetica] font-bold text-xs uppercase">Total Task</span>
            </div>
            <div class="bg-gray-100 px-4 py-4">
                <span class="font-['Arial_Black'] font-black text-3xl">{{ $totalContent ?? 0 }}</span>
            </div>
        </div>
        <div class="border-2 border-black">
            <div class="bg-white border-b-2 border-black px-3 py-1.5">
                <span class="font-[Helvetica] font-bold text-xs uppercase">Completed</span>
            </div>
            <div class="bg-[#b3bd95] px-4 py-4">
                <span class="font-['Arial_Black'] font-black text-3xl">{{ $totalPosted ?? 0 }}</span>
                <span class="font-[Helvetica] font-bold text-xs ml-1">SELESAI</span>
            </div>
        </div>
        <div class="border-2 border-black">
            <div class="bg-white border-b-2 border-black px-3 py-1.5">
                <span class="font-[Helvetica] font-bold text-xs uppercase">Completion Rate</span>
            </div>
            <div class="bg-[#8c9ae0] px-4 py-4">
                <span class="font-['Arial_Black'] font-black text-3xl">{{ $completionRate ?? 0 }}<span class="text-xl">%</span></span>
            </div>
        </div>
    </div>

    {{-- === TABBED TASK LIST === --}}
    <div x-data="{ tab: 'today' }" class="border-2 border-black mb-6">
        <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase flex items-center gap-4">
            <button @click="tab = 'today'" :class="tab === 'today' ? 'text-white underline' : 'text-white/60 hover:text-white'" class="uppercase">📋 Hari Ini</button>
            <button @click="tab = 'upcoming'" :class="tab === 'upcoming' ? 'text-white underline' : 'text-white/60 hover:text-white'" class="uppercase">📅 Akan Datang (7 Hari)</button>
            <button @click="tab = 'overdue'" :class="tab === 'overdue' ? 'text-white underline' : 'text-white/60 hover:text-white'" class="uppercase">⚠️ Terlewat</button>
        </div>

        {{-- Today Tab --}}
        <div x-show="tab === 'today'" x-cloak>
            @if(isset($todayAgenda) && $todayAgenda->count() > 0)
            <div class="divide-y divide-black">
                @foreach($todayAgenda as $item)
                <div class="px-3 py-2 text-sm font-['Times_New_Roman'] flex items-start gap-2">
                    <span style="background:{{ $item['color'] }}; min-width:4px; width:4px; align-self:stretch; display:block;" class="shrink-0"></span>
                    <div>
                        <div class="font-bold">{{ $item['label'] }}</div>
                        <div class="text-xs">{{ $item['subtitle'] }} — <span class="font-[Helvetica] font-bold">{{ strtoupper($item['status']) }}</span></div>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="px-4 py-6 text-center text-sm font-['Times_New_Roman']">
                Tidak ada agenda hari ini.
            </div>
            @endif
        </div>

        {{-- Upcoming Tab --}}
        <div x-show="tab === 'upcoming'" x-cloak>
            @if(isset($upcomingWeek) && $upcomingWeek->count() > 0)
            <div class="divide-y divide-black">
                @foreach($upcomingWeek as $item)
                <div class="px-3 py-2 text-sm font-['Times_New_Roman'] flex items-start gap-2">
                    <span style="background:#e6915d; min-width:4px; width:4px; align-self:stretch; display:block;" class="shrink-0"></span>
                    <div class="flex-1">
                        <div class="font-bold">{{ $item->title }}</div>
                        <div class="text-xs">{{ $item->deadline_date?->format('d M') }} — {{ $item->branch->name ?? '' }} — <span class="font-[Helvetica] font-bold">{{ strtoupper($item->status) }}</span></div>
                    </div>
                    <span class="text-xs font-[Helvetica] font-bold whitespace-nowrap">{{ $item->deadline_date?->diffForHumans() }}</span>
                </div>
                @endforeach
            </div>
            @else
            <div class="px-4 py-6 text-center text-sm font-['Times_New_Roman']">
                Tidak ada deadline dalam 7 hari ke depan.
            </div>
            @endif
        </div>

        {{-- Overdue Tab --}}
        <div x-show="tab === 'overdue'" x-cloak>
            @if(isset($overdueContent) && $overdueContent->count() > 0)
            <div class="divide-y divide-black">
                @foreach($overdueContent as $item)
                <div class="px-3 py-2 text-sm font-['Times_New_Roman'] flex items-start gap-2">
                    <span style="background:#d77a7a; min-width:4px; width:4px; align-self:stretch; display:block;" class="shrink-0"></span>
                    <div class="flex-1">
                        <div class="font-bold">{{ $item->title }}</div>
                        <div class="text-xs">{{ $item->deadline_date?->format('d M') }} — {{ $item->branch->name ?? '' }} — <span class="font-[Helvetica] font-bold">{{ strtoupper($item->status) }}</span></div>
                    </div>
                    <span class="text-xs font-[Helvetica] font-bold text-[#e91d2a] whitespace-nowrap">{{ $item->deadline_date?->diffForHumans() }}</span>
                </div>
                @endforeach
            </div>
            @else
            <div class="px-4 py-6 text-center text-sm font-['Times_New_Roman']">
                Tidak ada task terlewat.
            </div>
            @endif
        </div>
    </div>

    {{-- === BOTTOM 2-COL: Status Cabang (bar chart) + PIC Teratas === --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        {{-- Status Cabang --}}
        @if(Auth::user()->canViewAllBranches() && isset($branchStatuses))
        <div x-data="{ chartView: true }" class="lg:col-span-2 border-2 border-black">
            <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase flex items-center justify-between">
                <span>Status Cabang</span>
                <button @click="chartView = !chartView" class="text-white/70 hover:text-white text-[10px]">
                    <span x-text="chartView ? 'Lihat Tabel' : 'Lihat Grafik'"></span>
                </button>
            </div>

            {{-- Bar Chart View --}}
            <div x-show="chartView" class="px-3 py-3">
                @php
                    $sorted = $branchStatuses->sortBy('completion_rate');
                @endphp
                @foreach($sorted as $bs)
                <div class="mb-2">
                    <div class="flex items-center justify-between text-xs font-[Helvetica] font-bold mb-0.5">
                        <span>{{ $bs->name }}</span>
                        <span>{{ $bs->posted_count }}/{{ $bs->content_items_count }} ({{ $bs->completion_rate }}%)</span>
                    </div>
                    <div class="w-full bg-gray-200 border border-black" style="height:16px;">
                        <div class="h-full {{ $bs->completion_rate >= 75 ? 'bg-[#b3bd95]' : ($bs->completion_rate >= 50 ? 'bg-[#e6915d]' : 'bg-[#d77a7a]') }}" style="width:{{ $bs->completion_rate }}%;"></div>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- Table View --}}
            <div x-show="!chartView" x-cloak>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm font-['Times_New_Roman']">
                        <thead>
                            <tr class="border-b-2 border-black bg-white">
                                <th class="text-left px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Cabang</th>
                                <th class="text-center px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Total</th>
                                <th class="text-center px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Completed</th>
                                <th class="text-center px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">%</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-black">
                            @foreach($sorted as $bs)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-2 font-bold">{{ $bs->name }}</td>
                                <td class="px-3 py-2 text-center">{{ $bs->content_items_count }}</td>
                                <td class="px-3 py-2 text-center">{{ $bs->posted_count }}</td>
                                <td class="px-3 py-2 text-center font-[Helvetica] font-bold">{{ $bs->completion_rate }}%</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif

        {{-- PIC Teratas --}}
        <div class="border-2 border-black">
            <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">PIC Teratas</div>
            <div class="px-3 py-3">
                @if(isset($topPics) && count($topPics) > 0)
                    @foreach($topPics as $name => $count)
                    <div class="flex items-center justify-between text-sm font-[Helvetica] font-bold {{ $loop->last ? '' : 'mb-1.5' }}">
                        <span>{{ $name }}</span>
                        <span class="bg-white border border-black px-2 py-0.5 text-xs">{{ $count }} task</span>
                    </div>
                    @endforeach
                @else
                    <div class="text-center py-4">
                        <span class="text-sm font-['Times_New_Roman'] text-gray-500">Belum ada data PIC periode ini.</span>
                    </div>
                @endif
            </div>
        </div>
    </div>

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
