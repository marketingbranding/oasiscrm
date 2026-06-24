@extends('layouts.crm')

@section('title', 'Content Calendar - Oasis CRM')

@section('content')
    <div class="bg-[#b3bd95] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Content Calendar</h1>
    </div>

    @if(Auth::user()->canViewAllBranches())
    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('content-calendar.index') }}" class="flex items-center gap-3 flex-wrap filter-bar">
            <div class="flex items-center gap-3 flex-wrap">
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
            </div>

            <div class="h-6 w-px bg-black mx-1 hidden sm:block"></div>

            <div class="flex items-center gap-2 ml-auto">
                <a href="{{ route('content-calendar.export', request()->only(['month', 'year', 'branch_id', 'project_name'])) }}" class="bg-white text-black px-3 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                    ↓ Export XLSX
                </a>
                <a href="{{ route('content-calendar.create') }}" class="bg-[#b3bd95] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#9eaa7a]">
                    + Buat Konten
                </a>
            </div>
        </form>
    </div>
    @elseif(isset($projects) && $projects->count() > 0)
    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('content-calendar.index') }}" class="flex items-center gap-3 flex-wrap filter-bar">
            <div class="flex items-center gap-3 flex-wrap">
            <label class="font-[Helvetica] font-bold text-xs uppercase">Proyek:</label>
            <select name="project_name" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Proyek —</option>
                @foreach($projects as $p)
                    <option value="{{ $p->project_name }}" {{ $selectedProject === $p->project_name ? 'selected' : '' }}>{{ $p->project_name }}</option>
                @endforeach
            </select>
            </div>

            <div class="h-6 w-px bg-black mx-1 hidden sm:block"></div>

            <div class="flex items-center gap-2 ml-auto">
                <a href="{{ route('content-calendar.export', request()->only(['month', 'year', 'branch_id', 'project_name'])) }}" class="bg-white text-black px-3 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                    ↓ Export XLSX
                </a>
                <a href="{{ route('content-calendar.create') }}" class="bg-[#b3bd95] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#9eaa7a]">
                    + Buat Konten
                </a>
            </div>
        </form>
    </div>
    @endif

    <div class="flex mb-4">
        <div class="flex items-center gap-2">
            <a href="{{ route('content-calendar.index', array_filter(['month' => $prevMonth->month, 'year' => $prevMonth->year, 'branch_id' => request('branch_id'), 'project_name' => request('project_name')])) }}" class="bg-black text-white px-3 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                ← Prev
            </a>
            <span class="font-['Arial_Black'] font-black text-lg px-3">{{ $currentMonth->format('F Y') }}</span>
            <a href="{{ route('content-calendar.index', array_filter(['month' => $nextMonth->month, 'year' => $nextMonth->year, 'branch_id' => request('branch_id'), 'project_name' => request('project_name')])) }}" class="bg-black text-white px-3 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                Next →
            </a>
        </div>
    </div>

    @php
        $dayNames = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
        $statusColors = [
            'draft' => 'bg-[#9ab6c8]',
            'review' => 'bg-[#e6915d]',
            'approved' => 'bg-[#b3bd95]',
            'posted' => 'bg-[#c0d4a7]',
        ];
    @endphp

    <div class="border-2 border-black bg-white">
        <table class="w-full border-collapse" style="table-layout: fixed;">
            <thead>
                <tr>
                    @foreach($dayNames as $name)
                        <th class="bg-black text-white px-2 py-2 text-center font-[Helvetica] font-bold text-xs uppercase border border-black">{{ $name }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($calendar as $week)
                    <tr>
                        @foreach($week as $cell)
                            @php
                                $hasItems = $cell['day'] && $cell['items']->count() > 0;
                            @endphp
                            <td class="p-1.5 align-top border border-black {{ $cell['day'] ? 'bg-white' : 'bg-gray-100' }} {{ $cell['isToday'] ? 'ring-2 ring-inset ring-[#e91d2a]' : '' }}" style="height: 100px; vertical-align: top;">
                                @if($cell['day'])
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="font-['Arial_Black'] font-black text-sm {{ $cell['isToday'] ? 'text-[#e91d2a]' : 'text-black' }}">{{ $cell['day'] }}</span>
                                        @if($hasItems)
                                            <span class="text-[10px] font-[Helvetica] font-bold text-gray-500">{{ $cell['items']->count() }}</span>
                                        @endif
                                    </div>
                                    @foreach($cell['items'] as $item)
                                        <div class="flex {{ $statusColors[$item->status] ?? 'bg-gray-200' }} border border-black mb-0.5 rounded-none text-[10px] leading-tight">
                                            <a href="{{ route('content-calendar.edit', $item->id) }}"
                                               class="flex-1 px-1 py-0.5 font-[Helvetica] font-bold text-black truncate hover:opacity-80"
                                               title="{{ $item->title }} — {{ $item->project_name ?? '(tanpa proyek)' }} — {{ $item->platform }} — {{ strtoupper($item->status) }}">
                                                {{ $item->title }}
                                            </a>
                                            <form method="POST" action="{{ route('content-calendar.destroy', ['content_calendar' => $item->id]) }}"
                                                  onsubmit="return confirm('Hapus konten ini?')" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="month" value="{{ request('month') }}">
                                                <input type="hidden" name="year" value="{{ request('year') }}">
                                                <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
                                                <input type="hidden" name="project_name" value="{{ request('project_name') }}">
                                                <button type="submit" class="px-1 py-0.5 font-bold text-black hover:text-[#e91d2a] border-l border-black leading-tight" title="Hapus">×</button>
                                            </form>
                                        </div>
                                    @endforeach
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm font-['Times_New_Roman'] border border-black">
                            Tidak ada konten untuk bulan ini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center gap-4 text-xs font-[Helvetica] font-bold">
        <span class="flex items-center gap-1"><span class="bg-[#9ab6c8] border border-black px-2 py-0.5">DRAFT</span> Draft</span>
        <span class="flex items-center gap-1"><span class="bg-[#e6915d] border border-black px-2 py-0.5">REVIEW</span> Review</span>
        <span class="flex items-center gap-1"><span class="bg-[#b3bd95] border border-black px-2 py-0.5">APPROVED</span> Approved</span>
        <span class="flex items-center gap-1"><span class="bg-[#c0d4a7] border border-black px-2 py-0.5">POSTED</span> Posted</span>
    </div>
@endsection