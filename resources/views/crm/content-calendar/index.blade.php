@extends('layouts.crm')

@section('title', 'Task Tracker - Oasis CRM')

@section('content')
    <x-crm.page-header color="#b3bd95" title="Task Tracker" />

    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('content-calendar.index') }}" class="flex items-center gap-3 flex-wrap filter-bar">
            <div class="flex items-center gap-3 flex-wrap">
                @if(Auth::user()->canViewAllBranches() && isset($branches) && $branches->count() > 0)
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

                <label class="font-[Helvetica] font-bold text-xs uppercase">Status:</label>
                <select name="status" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                    <option value="">— Semua Status —</option>
                    <option value="todo" {{ $selectedStatus === 'todo' ? 'selected' : '' }}>To Do</option>
                    <option value="in_progress" {{ $selectedStatus === 'in_progress' ? 'selected' : '' }}>In Progress</option>
                    <option value="completed" {{ $selectedStatus === 'completed' ? 'selected' : '' }}>Completed</option>
                    <option value="lost_track" {{ $selectedStatus === 'lost_track' ? 'selected' : '' }}>Lost Track</option>
                </select>

                <label class="font-[Helvetica] font-bold text-xs uppercase">Prioritas:</label>
                <select name="priority" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                    <option value="">— Semua —</option>
                    <option value="low" {{ $selectedPriority === 'low' ? 'selected' : '' }}>Low</option>
                    <option value="medium" {{ $selectedPriority === 'medium' ? 'selected' : '' }}>Medium</option>
                    <option value="high" {{ $selectedPriority === 'high' ? 'selected' : '' }}>High</option>
                    <option value="urgent" {{ $selectedPriority === 'urgent' ? 'selected' : '' }}>Urgent</option>
                </select>
            </div>

            <div class="h-6 w-px bg-black mx-1 hidden sm:block"></div>

            <div class="flex items-center gap-2 ml-auto">
                <x-crm.export-import export-route="content-calendar.export" import-route="content-calendar.import" :params="request()->only(['month', 'year', 'branch_id', 'project_name', 'status', 'priority'])" />
                <a href="{{ route('content-calendar.create') }}" class="bg-[#b3bd95] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#9eaa7a]">
                    + Task Baru
                </a>
            </div>
        </form>
    </div>

    <div class="flex mb-4">
        <div class="flex items-center gap-2">
            <a href="{{ route('content-calendar.index', array_filter(['month' => $prevMonth->month, 'year' => $prevMonth->year, 'branch_id' => request('branch_id'), 'project_name' => request('project_name'), 'status' => request('status'), 'priority' => request('priority')])) }}" class="bg-black text-white px-3 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                ← Prev
            </a>
            <span class="font-['Arial_Black'] font-black text-lg px-3">{{ $currentMonth->format('F Y') }}</span>
            <a href="{{ route('content-calendar.index', array_filter(['month' => $nextMonth->month, 'year' => $nextMonth->year, 'branch_id' => request('branch_id'), 'project_name' => request('project_name'), 'status' => request('status'), 'priority' => request('priority')])) }}" class="bg-black text-white px-3 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                Next →
            </a>
        </div>
    </div>

    @php
        $dayNames = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
        $statusColors = [
            'todo' => 'bg-[#9ab6c8]',
            'in_progress' => 'bg-[#e6915d]',
            'completed' => 'bg-[#b3bd95]',
            'lost_track' => 'bg-[#d77a7a]',
        ];
        $statusLabels = [
            'todo' => 'TO DO',
            'in_progress' => 'IN PROGRESS',
            'completed' => 'COMPLETED',
            'lost_track' => 'LOST TRACK',
        ];
        $priorityLabels = [
            'low' => 'LOW',
            'medium' => 'MED',
            'high' => 'HIGH',
            'urgent' => 'URGENT',
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
                            <td class="p-1.5 align-top border border-black {{ $cell['day'] ? 'bg-white' : 'bg-gray-100' }} {{ $cell['isToday'] ? 'ring-2 ring-inset ring-[#e91d2a]' : '' }}" style="height: 124px; vertical-align: top;">
                                @if($cell['day'])
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="font-['Arial_Black'] font-black text-sm {{ $cell['isToday'] ? 'text-[#e91d2a]' : 'text-black' }}">{{ $cell['day'] }}</span>
                                        @if($hasItems)
                                            <span class="text-[10px] font-[Helvetica] font-bold text-gray-500">{{ $cell['items']->count() }}</span>
                                        @endif
                                    </div>
                                    @foreach($cell['items'] as $item)
                                        @php
                                            $deadline = $item->deadline_date ?? $item->scheduled_date;
                                            $isOverdue = $deadline->isPast() && !$deadline->isToday() && $item->status !== 'completed';
                                            $duration = $item->start_date ? $item->start_date->diffInDays($deadline) : null;
                                        @endphp
                                        <div class="{{ $statusColors[$item->status] ?? 'bg-gray-200' }} border {{ $isOverdue ? 'border-[#e91d2a] border-2' : 'border-black' }} mb-1 rounded-none text-[10px] leading-tight {{ $item->status === 'completed' ? 'opacity-70' : '' }}">
                                            <a href="{{ route('content-calendar.edit', $item->id) }}"
                                               class="block px-1 py-0.5 font-[Helvetica] font-bold text-black hover:opacity-80"
                                               title="{{ $item->title }} — {{ $item->project_name ?? '(tanpa proyek)' }} — {{ $statusLabels[$item->status] ?? strtoupper($item->status) }}">
                                                <span class="block truncate">{{ $item->title }}</span>
                                                <span class="block font-['Times_New_Roman'] font-normal truncate">{{ $item->project_name ?? 'Tanpa proyek' }} @if($item->pic_names && count($item->pic_names) > 0) — {{ implode(', ', $item->pic_names) }} @endif</span>
                                                <span class="block font-[Helvetica] font-bold">{{ $statusLabels[$item->status] ?? strtoupper($item->status) }} · {{ $priorityLabels[$item->priority] ?? strtoupper($item->priority ?? 'medium') }} @if($duration !== null) · {{ $duration }}d @endif</span>
                                            </a>
                                            <form method="POST" action="{{ route('content-calendar.destroy', ['content_calendar' => $item->id]) }}" onsubmit="return confirm('Hapus task ini?')" class="border-t border-black">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="month" value="{{ request('month') }}">
                                                <input type="hidden" name="year" value="{{ request('year') }}">
                                                <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
                                                <input type="hidden" name="project_name" value="{{ request('project_name') }}">
                                                <input type="hidden" name="status" value="{{ request('status') }}">
                                                <input type="hidden" name="priority" value="{{ request('priority') }}">
                                                <button type="submit" class="w-full px-1 py-0.5 font-bold text-black hover:text-[#e91d2a] leading-tight" title="Hapus">×</button>
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
                            Tidak ada task untuk bulan ini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center gap-4 text-xs font-[Helvetica] font-bold flex-wrap">
        <span class="flex items-center gap-1"><span class="bg-[#9ab6c8] border border-black px-2 py-0.5">TO DO</span> To Do</span>
        <span class="flex items-center gap-1"><span class="bg-[#e6915d] border border-black px-2 py-0.5">IN PROGRESS</span> In Progress</span>
        <span class="flex items-center gap-1"><span class="bg-[#b3bd95] border border-black px-2 py-0.5">COMPLETED</span> Completed</span>
        <span class="flex items-center gap-1"><span class="bg-[#d77a7a] border border-black px-2 py-0.5">LOST TRACK</span> Lost Track</span>
        <span class="flex items-center gap-1"><span class="border-2 border-[#e91d2a] px-2 py-0.5">OVERDUE</span> Past deadline</span>
    </div>
@endsection
