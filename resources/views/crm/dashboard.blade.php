@extends('layouts.crm')

@section('title', 'Dashboard - Oasis CRM')

@section('content')
    @if(isset($branches) && Auth::user()->isSuperadmin() && $branches->count() > 0)
    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('dashboard') }}" class="flex items-center gap-3 flex-wrap">
            <label class="font-[Helvetica] font-bold text-xs uppercase">Pilih Cabang:</label>
            <select name="branch_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Cabang —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </select>
        </form>
    </div>
    @endif

    @if(isset($branch) && $branch)
    <div class="bg-[#8c9ae0] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">{{ $branch->name }}</h1>
    </div>
    @else
    <div class="bg-[#8c9ae0] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Dashboard</h1>
    </div>
    @endif

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

        @if(Auth::user()->isSuperadmin() && isset($branchStatuses))
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
            <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">Update Terbaru</div>
            @if(isset($recentUpdates) && $recentUpdates->count() > 0)
            <div class="divide-y-2 divide-black">
                @foreach($recentUpdates as $update)
                <div class="px-3 py-2 text-sm font-['Times_New_Roman']">
                    <div class="font-bold">{{ $update->title }}</div>
                    <div class="text-xs">{{ $update->created_at->diffForHumans() }} — {{ $update->creator->name ?? '' }}</div>
                </div>
                @endforeach
            </div>
            @else
            <div class="px-4 py-6 text-center text-sm">
                Belum ada update terbaru.
            </div>
            @endif
        </div>
        @endif
    </div>
@endsection
