@extends('layouts.crm')

@section('title', 'Task Tracker - Oasis CRM')

@section('content')
    <div x-data="taskDetailModal()">
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

                <label class="font-[Helvetica] font-bold text-xs uppercase">PIC:</label>
                <input type="text" name="pic" value="{{ $selectedPic ?? '' }}" placeholder="Cari PIC..."
                       onchange="this.form.submit()"
                       class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none w-32">
            </div>

            <div class="h-6 w-px bg-black mx-1 hidden sm:block"></div>

            <div class="flex items-center gap-2 ml-auto">
                <x-crm.export-import export-route="content-calendar.export" import-route="content-calendar.import" :params="request()->only(['month', 'year', 'branch_id', 'project_name', 'status', 'priority', 'pic'])" />
                <a href="{{ route('content-calendar.create') }}" class="bg-[#b3bd95] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#9eaa7a]">
                    + Task Baru
                </a>
            </div>
        </form>
    </div>

    <div class="flex mb-4">
        <div class="flex items-center gap-2">
            <a href="{{ route('content-calendar.index', array_filter(['month' => $prevMonth->month, 'year' => $prevMonth->year, 'branch_id' => request('branch_id'), 'project_name' => request('project_name'), 'status' => request('status'), 'priority' => request('priority'), 'pic' => request('pic')])) }}" class="bg-black text-white px-3 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                ← Prev
            </a>
            <span class="font-['Arial_Black'] font-black text-lg px-3">{{ $currentMonth->format('F Y') }}</span>
            <a href="{{ route('content-calendar.index', array_filter(['month' => $nextMonth->month, 'year' => $nextMonth->year, 'branch_id' => request('branch_id'), 'project_name' => request('project_name'), 'status' => request('status'), 'priority' => request('priority'), 'pic' => request('pic')])) }}" class="bg-black text-white px-3 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
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
                            <td class="p-1.5 align-top border border-black {{ $cell['day'] ? 'bg-white' : 'bg-gray-100' }} {{ $cell['isToday'] ? 'ring-2 ring-inset ring-[#e91d2a]' : '' }}" style="height: 140px; vertical-align: top;">
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
                                            $isApproaching = !$isOverdue && $item->status !== 'completed' && $deadline->diffInDays(now()) <= 7;
                                            $daysLeft = $isApproaching ? round($deadline->diffInDays(now()), 1) : null;
                                            $duration = $item->start_date ? $item->start_date->diffInDays($deadline) : null;
                                        @endphp
                                        <div class="{{ $statusColors[$item->status] ?? 'bg-gray-200' }} border {{ $isOverdue ? 'border-[#e91d2a] border-2' : 'border-black' }} mb-1 rounded-none text-xs leading-tight {{ $item->status === 'completed' ? 'opacity-70' : '' }}">
                                            @if($isApproaching)
                                                <div class="bg-[#e91d2a] text-yellow-300 text-[10px] font-mono font-bold text-center px-1 py-0.5">
                                                    {{ $deadline->isToday() ? 'Deadline hari ini!' : ($deadline->isTomorrow() ? 'Besok deadline!' : $daysLeft . ' hari lagi') }}
                                                </div>
                                            @endif
                                            <a href="#"
                                               @click.prevent="openDetail({{ $item->id }})"
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
                                                <input type="hidden" name="pic" value="{{ request('pic') }}">
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
        <span class="flex items-center gap-1"><span class="bg-[#e91d2a] text-yellow-300 border border-black px-2 py-0.5">APPROACHING</span> ≤7 hari</span>
    </div>

    {{-- Detail Modal --}}
    <div x-show="open"
         x-cloak
         @keydown.escape.window="close()"
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="background: rgba(0,0,0,0.5);">
        <div @click.away="close()"
             class="relative bg-[#f5f0eb] border border-black w-full max-w-lg mx-auto max-h-[80vh] overflow-y-auto shadow-[4px_4px_0_#000] rotate-[-0.3deg]">
            {{-- paper clip --}}
            <div class="absolute -top-2 -right-2 z-10 text-2xl select-none" aria-hidden="true">&#x1F4CE;</div>
            {{-- typewriter header --}}
            <div class="bg-[#2a2a2a] text-white px-4 py-2.5 font-mono text-xs tracking-[0.2em] uppercase flex items-center justify-between border-b border-[#d4c9b8]">
                <span x-text="task ? task.title : 'Detail Task'" class="truncate mr-2"></span>
                <button @click="close()" class="text-white/70 hover:text-white text-lg leading-none shrink-0">&times;</button>
            </div>
            <div x-show="loading" class="p-6 text-center text-sm font-['Times_New_Roman'] text-gray-500">Memuat...</div>
            <template x-if="!loading && task">
                <div class="text-sm font-['Times_New_Roman']">
                    {{-- detail body --}}
                    <div class="px-5 py-4 border-b border-dashed border-[#d4c9b8]">
                        <p x-text="task.task_detail || '—'" class="text-sm leading-relaxed text-gray-800 italic"></p>
                    </div>
                    {{-- fields grid --}}
                    <div class="px-5 py-4 grid grid-cols-2 gap-x-6 gap-y-3 text-sm border-b border-dashed border-[#d4c9b8]">
                        <div>
                            <span class="font-mono text-[10px] tracking-wider uppercase text-gray-500">Proyek</span>
                            <p x-text="task.project_name || '—'" class="mt-0.5 text-gray-900"></p>
                        </div>
                        <div>
                            <span class="font-mono text-[10px] tracking-wider uppercase text-gray-500">Channel</span>
                            <p x-text="task.platform || '—'" class="mt-0.5 text-gray-900"></p>
                        </div>
                        <div>
                            <span class="font-mono text-[10px] tracking-wider uppercase text-gray-500">Mulai</span>
                            <p x-text="formatDate(task.start_date)" class="mt-0.5 text-gray-900"></p>
                        </div>
                        <div>
                            <span class="font-mono text-[10px] tracking-wider uppercase text-gray-500">Deadline</span>
                            <p x-text="formatDate(task.deadline_date || task.scheduled_date)" class="mt-0.5 text-gray-900"></p>
                        </div>
                        <div>
                            <span class="font-mono text-[10px] tracking-wider uppercase text-gray-500">Status</span>
                            <p class="mt-0.5">
                                <span x-text="task.status ? task.status.replace('_', ' ').toUpperCase() : '—'"
                                      class="border border-black px-1.5 py-0.5 text-[10px] font-mono font-bold tracking-wider"
                                      :style="'background:' + (statusColors[task.status] || '#ccc')"></span>
                            </p>
                        </div>
                        <div>
                            <span class="font-mono text-[10px] tracking-wider uppercase text-gray-500">Prioritas</span>
                            <p x-text="task.priority ? task.priority.toUpperCase() : '—'" class="mt-0.5 text-gray-900 font-bold"></p>
                        </div>
                        <div class="col-span-2">
                            <span class="font-mono text-[10px] tracking-wider uppercase text-gray-500">PIC</span>
                            <div class="flex flex-wrap gap-1.5 mt-1">
                                <template x-for="name in (task.pic_names || [])" :key="name">
                                    <span class="border border-black bg-[#b3bd95] px-2 py-0.5 text-[11px] font-mono font-bold" x-text="name"></span>
                                </template>
                                <span x-show="!task.pic_names || task.pic_names.length === 0" class="text-sm text-gray-500">—</span>
                            </div>
                        </div>
                    </div>
                    {{-- notes --}}
                    <div class="px-5 py-4 border-b border-dashed border-[#d4c9b8]">
                        <span class="font-mono text-[10px] tracking-wider uppercase text-gray-500">Catatan</span>
                        <p x-text="task.notes || '—'" class="mt-1 text-sm leading-relaxed text-gray-800"></p>
                    </div>
                    {{-- footer --}}
                    <div class="px-5 py-3 flex items-center justify-between text-[10px] text-gray-500 font-mono">
                        <span x-text="'Dibuat oleh: ' + (task.creator ? task.creator.name : '—')"></span>
                        <div class="flex gap-2">
                            <button @click="close()" class="bg-white text-black px-3 py-1 text-xs font-mono font-bold border border-black hover:bg-gray-100">Tutup</button>
                            <a :href="'/content-calendar/' + task.id + '/edit'" class="bg-[#b3bd95] text-black px-3 py-1 text-xs font-mono font-bold border border-black hover:bg-[#9eaa7a]">Edit</a>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
    </div>
@endsection

@push('scripts')
<script>
function taskDetailModal() {
    return {
        open: false,
        loading: false,
        task: null,
        statusColors: { todo: '#9ab6c8', in_progress: '#e6915d', completed: '#b3bd95', lost_track: '#d77a7a' },
        formatDate(dateStr) {
            if (!dateStr) return '—';
            return new Date(dateStr).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
        },
        openDetail(id) {
            this.open = true;
            this.loading = true;
            this.task = null;
            fetch('/content-calendar/' + id + '/detail')
                .then(r => r.json())
                .then(data => {
                    this.task = data;
                    this.loading = false;
                })
                .catch(() => {
                    this.loading = false;
                    alert('Gagal memuat detail task.');
                });
        },
        close() {
            this.open = false;
            this.loading = false;
            this.task = null;
        }
    };
}
</script>
@endpush
