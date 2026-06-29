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
            <a href="{{ route('leads.create') }}" class="flex items-center gap-3 px-3 py-2 text-sm font-[Helvetica] font-bold border-l-4 border-[#e6915d] hover:bg-gray-50">
                <span>+ Lead Baru</span>
            </a>
            <a href="{{ route('content-calendar.create') }}" class="flex items-center gap-3 px-3 py-2 text-sm font-[Helvetica] font-bold border-l-4 border-[#b3bd95] hover:bg-gray-50">
                <span>+ Buat Konten</span>
            </a>
            <a href="{{ route('dana-talangan.create') }}" class="flex items-center gap-3 px-3 py-2 text-sm font-[Helvetica] font-bold border-l-4 border-[#f1c40f] hover:bg-gray-50">
                <span>+ Dana Talangan</span>
            </a>
            <a href="{{ route('projects.create') }}" class="flex items-center gap-3 px-3 py-2 text-sm font-[Helvetica] font-bold border-l-4 border-[#5d8e8e] hover:bg-gray-50">
                <span>+ Proyek Baru</span>
            </a>
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

    {{-- Header --}}
    @if(isset($branch) && $branch)
    <div class="bg-[#8c9ae0] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">{{ $branch->name }}</h1>
    </div>
    @else
    <div class="bg-[#8c9ae0] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Dashboard</h1>
    </div>
    @endif

    {{-- Stat Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="border-2 border-black">
            <div class="bg-white border-b-2 border-black px-3 py-1.5">
                <span class="font-[Helvetica] font-bold text-xs uppercase">Total Konten</span>
            </div>
            <div class="bg-[#b3bd95] px-4 py-4">
                <span class="font-['Arial_Black'] font-black text-3xl">{{ $totalContent ?? 0 }}</span>
            </div>
        </div>
        <div class="border-2 border-black">
            <div class="bg-white border-b-2 border-black px-3 py-1.5">
                <span class="font-[Helvetica] font-bold text-xs uppercase">Jadwal Mendatang</span>
            </div>
            <div class="bg-[#9ab6c8] px-4 py-4">
                <span class="font-['Arial_Black'] font-black text-3xl">{{ $upcomingContent ? $upcomingContent->count() : 0 }}</span>
            </div>
        </div>
        <div class="border-2 border-black">
            <div class="bg-white border-b-2 border-black px-3 py-1.5">
                <span class="font-[Helvetica] font-bold text-xs uppercase">Status Konten</span>
            </div>
            <div class="bg-[#c0d4a7] px-4 py-4">
                <span class="font-['Arial_Black'] font-black text-3xl">{{ $totalPosted ?? 0 }}</span>
                <span class="font-[Helvetica] font-bold text-xs ml-1">POSTED</span>
            </div>
        </div>
    </div>

    {{-- Today's Agenda --}}
    <div class="border-2 border-black mb-6">
        <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">📋 Hari Ini</div>
        @if(isset($todayAgenda) && $todayAgenda->count() > 0)
        <div class="divide-y-2 divide-black">
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

    {{-- Lower Content --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="border-2 border-black">
            <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Jadwal Mendatang</div>
            @if(isset($upcomingContent) && $upcomingContent->count() > 0)
            <div class="divide-y-2 divide-black">
                @foreach($upcomingContent as $item)
                <div class="px-3 py-2 text-sm font-['Times_New_Roman']">
                    <div class="font-bold">{{ $item->title }}</div>
                    <div class="text-xs">{{ $item->scheduled_date->format('d M Y') }} — {{ $item->branch->name ?? '' }} — <span class="font-[Helvetica] font-bold">{{ strtoupper($item->status) }}</span></div>
                </div>
                @endforeach
            </div>
            @else
            <div class="px-4 py-6 text-center text-sm font-['Times_New_Roman']">
                Tidak ada jadwal konten mendatang.
            </div>
            @endif
        </div>

        @if(Auth::user()->canViewAllBranches() && isset($branchStatuses))
        <div class="border-2 border-black">
            <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Status Cabang</div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm font-['Times_New_Roman']">
                    <thead>
                        <tr class="border-b-2 border-black bg-white">
                            <th class="text-left px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Cabang</th>
                            <th class="text-center px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Total</th>
                            <th class="text-center px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Posted</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-black">
                        @foreach($branchStatuses as $bs)
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2 font-bold">{{ $bs->name }}</td>
                            <td class="px-3 py-2 text-center">{{ $bs->content_items_count }}</td>
                            <td class="px-3 py-2 text-center">{{ $bs->posted_count }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @else
        <div class="border-2 border-black">
            <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">⚡ Aktivitas Terbaru</div>
            @if(isset($recentActivity) && $recentActivity->count() > 0)
            <div class="divide-y-2 divide-black">
                @foreach($recentActivity as $act)
                <div class="px-3 py-2 text-sm font-['Times_New_Roman'] flex items-start gap-2">
                    <span style="background:{{ $act['color'] }}; width:8px; height:8px; border-radius:50%; display:inline-block; margin-top:6px;" class="shrink-0"></span>
                    <div>
                        <div class="font-bold">{{ $act['text'] }}</div>
                        <div class="text-xs">{{ $act['time']->diffForHumans() }} — {{ $act['user'] }}</div>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="px-4 py-6 text-center text-sm font-['Times_New_Roman']">
                Belum ada aktivitas terbaru.
            </div>
            @endif
        </div>
        @endif
    </div>

    {{-- Activity Feed for superadmin (full width, below everything) --}}
    @if(Auth::user()->canViewAllBranches() && isset($recentActivity) && $recentActivity->count() > 0)
    <div class="border-2 border-black mt-6">
        <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">⚡ Aktivitas Terbaru</div>
        <div class="divide-y-2 divide-black">
            @foreach($recentActivity as $act)
            <div class="px-3 py-2 text-sm font-['Times_New_Roman'] flex items-start gap-2">
                <span style="background:{{ $act['color'] }}; width:8px; height:8px; border-radius:50%; display:inline-block; margin-top:6px;" class="shrink-0"></span>
                <div>
                    <div class="font-bold">{{ $act['text'] }}</div>
                    <div class="text-xs">{{ $act['time']->diffForHumans() }} — {{ $act['user'] }}</div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif
@endsection
