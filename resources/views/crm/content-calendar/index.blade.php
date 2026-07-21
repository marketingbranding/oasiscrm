@extends('layouts.crm')
@section('title', 'Work Planner - Oasis CRM')
@section('content')
@php
    $tabs = ['today' => 'Hari Ini', 'calendar' => 'Kalender', 'tasks' => 'Tugas', 'agenda' => 'Agenda', 'content' => 'Konten', 'all' => 'Semua'];
    $statusLabels = [
        'todo'=>'To Do','in_progress'=>'In Progress','completed'=>'Completed','lost_track'=>'Lost Track',
        'planned'=>'Planned','confirmed'=>'Confirmed','done'=>'Done','cancelled'=>'Cancelled',
        'idea'=>'Ide','content_in_progress'=>'Dalam Proses','done_editing'=>'Selesai Edit','uploaded'=>'Di Upload',
    ];
    $fixedType = match ($viewMode) {
        'tasks' => 'task',
        'agenda' => 'agenda',
        'content' => 'content',
        default => null,
    };
    $activeFilters = [];
    if ($selectedBranchId && $branches->count() > 1) $activeFilters[] = 'Cabang: '.($branches->firstWhere('id', $selectedBranchId)?->name ?? $selectedBranchId);
    if ($selectedProject) $activeFilters[] = 'Proyek: '.$selectedProject;
    if ($selectedType && !$fixedType) $activeFilters[] = 'Tipe: '.ucfirst($selectedType);
    if ($selectedStatus) $activeFilters[] = 'Status: '.($statusLabels[$selectedStatus] ?? $selectedStatus);
    if ($selectedPriority) $activeFilters[] = 'Prioritas: '.ucfirst($selectedPriority);
    if ($selectedPic) $activeFilters[] = 'PIC: '.$selectedPic;
@endphp
<div x-data="plannerPage(@js($allItemIds), {
    branchId: @js((string) $selectedBranchId),
    projectName: @js($selectedProject ?: ''),
    type: @js($fixedType ?: ($selectedType ?: '')),
    status: @js($selectedStatus ?: ''),
    fixedType: @js($fixedType),
    returnView: @js($viewMode),
    projects: @js($filterProjects->map(fn($project) => ['name' => $project->project_name, 'branch_id' => (string) $project->branch_id])->values()),
})">
    <x-crm.page-header color="#b3bd95" title="Work Planner" />
    <x-crm.page-presence page-key="work-planner" :branch-id="$selectedBranchId" />

    <nav class="flex overflow-x-auto border-b-2 border-black mb-4">
        @foreach($tabs as $key => $label)
        <a href="{{ route('content-calendar.index', ['view'=>$key]) }}" class="shrink-0 border-2 border-b-0 border-black px-4 py-2 text-xs font-[Helvetica] font-bold uppercase {{ $viewMode === $key ? 'bg-[#b3bd95]' : 'bg-white' }}">{{ $label }}</a>
        @endforeach
    </nav>

    <div class="border-2 border-black bg-white p-3 mb-3">
        <div class="flex items-center gap-2 flex-wrap">
            <form method="GET" action="{{ route('content-calendar.index') }}" class="flex grow min-w-[240px] max-w-xl">
                @foreach(request()->except(['search','page','view','item_type','status']) as $key => $value) @if(is_scalar($value) && $value !== '')<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
                <input type="hidden" name="view" value="{{ $viewMode }}">
                @if($selectedType)<input type="hidden" name="item_type" value="{{ $selectedType }}">@endif
                @if($selectedStatus)<input type="hidden" name="status" value="{{ $selectedStatus }}">@endif
                <input name="search" value="{{ $search }}" aria-label="Cari Work Planner" placeholder="Cari judul atau detail..." class="min-w-0 grow border-2 border-r-0 border-black px-3 py-1.5 text-sm">
                <button class="border-2 border-black bg-black text-white px-4 py-1.5 text-sm font-bold">Cari</button>
            </form>
            <button type="button" @click="filterOpen=true" class="inline-flex items-center gap-2 border-2 border-black bg-white px-4 py-1.5 text-sm font-bold hover:bg-gray-100">
                <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path d="M2.75 3.5a.75.75 0 0 1 .75-.75h13a.75.75 0 0 1 .53 1.28l-5.28 5.28v4.94a.75.75 0 0 1-.36.64l-3 1.8A.75.75 0 0 1 7.25 16V9.31L1.97 4.03a.75.75 0 0 1 .78-1.23v.7Zm2.56.75 3.22 3.22a.75.75 0 0 1 .22.53v6.68l1.5-.9V8a.75.75 0 0 1 .22-.53l3.22-3.22H5.31Z"/></svg>
                Filter @if(count($activeFilters))<span class="bg-[#c0392b] text-white min-w-5 h-5 px-1 inline-flex items-center justify-center text-[10px]">{{ count($activeFilters) }}</span>@endif
            </button>
            <div class="ml-auto flex gap-2 items-center">
                <x-crm.export-import export-route="content-calendar.export" import-route="content-calendar.import" :params="request()->query()" />
                <div class="relative" @click.outside="addOpen=false">
                    <button type="button" @click="addOpen=!addOpen" class="border-2 border-black bg-[#b3bd95] px-4 py-1.5 text-sm font-bold">+ Tambah ▼</button>
                    <div x-show="addOpen" x-cloak class="absolute right-0 top-full mt-1 z-40 w-44 border-2 border-black bg-white shadow-[4px_4px_0_#000]">
                        <a href="{{ route('content-calendar.create', ['type'=>'task','view'=>$viewMode]) }}" class="block border-l-4 border-[#9ab6c8] px-3 py-2 text-sm font-bold hover:bg-gray-100">Task</a>
                        <a href="{{ route('content-calendar.create', ['type'=>'agenda','view'=>$viewMode]) }}" class="block border-l-4 border-[#e6915d] px-3 py-2 text-sm font-bold hover:bg-gray-100">Agenda</a>
                        <a href="{{ route('content-calendar.create', ['type'=>'content','view'=>$viewMode]) }}" class="block border-l-4 border-[#8c9ae0] px-3 py-2 text-sm font-bold hover:bg-gray-100">Konten</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(count($activeFilters))
    <div class="flex flex-wrap gap-2 items-center mb-4"><span class="text-[10px] font-bold uppercase">Filter aktif:</span>@foreach($activeFilters as $activeFilter)<span class="border-2 border-black bg-[#fef3cd] px-2 py-1 text-xs">{{ $activeFilter }}</span>@endforeach<a href="{{ route('content-calendar.index', array_filter(['view'=>$viewMode,'month'=>$month,'year'=>$year,'search'=>$search])) }}" class="text-xs text-[#c0392b] font-bold underline">Hapus semua filter</a></div>
    @endif

    <div x-show="filterOpen" x-cloak class="fixed inset-0 z-50 bg-black/70 flex items-center justify-center p-4" @keydown.escape.window="filterOpen=false">
        <div @click.away="filterOpen=false" class="w-full max-w-2xl border-2 border-black bg-white p-5 shadow-[8px_8px_0_#000] max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4"><h2 class="text-sm font-bold uppercase">Filter Work Planner</h2><button @click="filterOpen=false" type="button">×</button></div>
            <form method="GET" action="{{ route('content-calendar.index') }}" class="space-y-4">
                <input type="hidden" name="view" value="{{ $viewMode }}"><input type="hidden" name="month" value="{{ $month }}"><input type="hidden" name="year" value="{{ $year }}">@if($search)<input type="hidden" name="search" value="{{ $search }}">@endif
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @if($branches->count() > 1)<div><label class="block text-xs font-bold uppercase mb-1">Cabang</label><select name="branch_id" x-model="filterBranch" @change="filterProject=''" class="w-full border-2 border-black px-3 py-2 bg-white"><option value="">Cabang Utama</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @if(str_contains(mb_strtolower($branch->name), 'pusat')) style="color:#b8860b;font-weight:700;background:#fff3b0" @endif>{{ $branch->name }}</option>@endforeach</select></div>@endif
                    <div><label class="block text-xs font-bold uppercase mb-1">Proyek</label><select name="project_name" x-model="filterProject" class="w-full border-2 border-black px-3 py-2 bg-white"><option value="">Semua Proyek</option><template x-for="project in filterProjectOptions"><option :value="project.name" x-text="project.name"></option></template></select></div>
                    <div><label class="block text-xs font-bold uppercase mb-1">Tipe</label>@if($fixedType)<div class="border-2 border-black bg-gray-100 px-3 py-2">{{ ucfirst($fixedType) }} <span class="text-xs font-normal">(konteks tab)</span></div>@else<select name="item_type" x-model="filterType" @change="syncFilterStatus()" class="w-full border-2 border-black px-3 py-2 bg-white"><option value="">Semua Tipe</option><option value="task">Task</option><option value="agenda">Agenda</option><option value="content">Konten</option></select>@endif</div>
                    <div><label class="block text-xs font-bold uppercase mb-1">Status</label><select name="status" x-model="filterStatus" class="w-full border-2 border-black px-3 py-2 bg-white"><option value="">Semua Status</option><template x-for="option in filterStatusOptions"><option :value="option.value" x-text="option.label"></option></template></select></div>
                    <div x-show="!filterType || filterType==='task'"><label class="block text-xs font-bold uppercase mb-1">Prioritas</label><select name="priority" class="w-full border-2 border-black px-3 py-2 bg-white"><option value="">Semua Prioritas</option>@foreach(['low','medium','high','urgent'] as $priority)<option value="{{ $priority }}" {{ $selectedPriority===$priority?'selected':'' }}>{{ ucfirst($priority) }}</option>@endforeach</select></div>
                    <div><label class="block text-xs font-bold uppercase mb-1">PIC</label><input name="pic" value="{{ $selectedPic }}" placeholder="Nama PIC" class="w-full border-2 border-black px-3 py-2"></div>
                </div>
                <div class="flex gap-2"><button class="bg-black text-white border-2 border-black px-6 py-2 text-sm font-bold">Terapkan Filter</button><a href="{{ route('content-calendar.index', array_filter(['view'=>$viewMode,'month'=>$month,'year'=>$year,'search'=>$search])) }}" class="border-2 border-black px-6 py-2 text-sm font-bold">Reset Filter</a></div>
            </form>
        </div>
    </div>

    @if($viewMode === 'today')
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <section class="border-2 border-black bg-[#fff8ed]"><h2 class="bg-[#e6915d] border-b-2 border-black px-3 py-2 text-xs font-bold uppercase">Agenda Hari Ini · {{ $agendaToday->count() }}</h2><div class="p-3 space-y-2">@forelse($agendaToday as $plannerItem) @include('crm.content-calendar._item-card') @empty <p class="text-sm italic">Tidak ada agenda hari ini.</p> @endforelse</div></section>
        <section class="border-2 border-black bg-[#f4f8fb]"><h2 class="bg-[#9ab6c8] border-b-2 border-black px-3 py-2 text-xs font-bold uppercase">Task Hari Ini · {{ $tasksToday->count() }}</h2><div class="p-3 space-y-2">@forelse($tasksToday as $plannerItem) @include('crm.content-calendar._item-card') @empty <p class="text-sm italic">Tidak ada task jatuh tempo hari ini.</p> @endforelse</div></section>
        <section class="border-2 border-black bg-[#fff1f1]"><h2 class="bg-[#d77a7a] border-b-2 border-black px-3 py-2 text-xs font-bold uppercase">Overdue · {{ $overdueTasks->count() }}</h2><div class="p-3 space-y-2">@forelse($overdueTasks as $plannerItem) @include('crm.content-calendar._item-card') @empty <p class="text-sm italic">Tidak ada task overdue.</p> @endforelse</div></section>
        <section class="border-2 border-black bg-[#f4f3ff]"><h2 class="bg-[#8c9ae0] text-white border-b-2 border-black px-3 py-2 text-xs font-bold uppercase">Konten Hari Ini · {{ $contentToday->count() }}</h2><div class="p-3 space-y-2">@forelse($contentToday as $plannerItem) @include('crm.content-calendar._item-card') @empty <p class="text-sm italic">Tidak ada konten terbit hari ini.</p> @endforelse</div></section>
    </div>
    @if($tomorrowItems->isNotEmpty())<section class="border-2 border-black bg-white mt-4"><h2 class="bg-black text-white px-3 py-2 text-xs font-bold uppercase">Besok / H-1 · {{ $tomorrowItems->count() }}</h2><div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 p-3">@foreach($tomorrowItems as $plannerItem) @include('crm.content-calendar._item-card') @endforeach</div></section>@endif

    @elseif($viewMode === 'calendar')
    <div class="flex items-center gap-3 mb-3"><a href="{{ route('content-calendar.index', array_merge(request()->query(), ['view'=>'calendar','month'=>$prevMonth->month,'year'=>$prevMonth->year])) }}" class="border-2 border-black bg-black text-white px-3 py-1 text-xs font-bold">←</a><strong>{{ $currentMonth->translatedFormat('F Y') }}</strong><a href="{{ route('content-calendar.index', array_merge(request()->query(), ['view'=>'calendar','month'=>$nextMonth->month,'year'=>$nextMonth->year])) }}" class="border-2 border-black bg-black text-white px-3 py-1 text-xs font-bold">→</a></div>
    <div class="overflow-x-auto border-2 border-black bg-white">
        <div class="min-w-[840px] grid grid-cols-7">
            @foreach(['Sen','Sel','Rab','Kam','Jum','Sab','Min'] as $day)
            <div class="bg-black text-white border-r-2 last:border-r-0 border-black p-2 text-center text-xs font-bold uppercase">{{ $day }}</div>
            @endforeach
            @foreach($calendar as $week)
                @foreach($week as $cell)
                @php
                    $dayPayload = $cell['items']->map(fn($item) => [
                        'id' => $item->id,
                        'title' => $item->title,
                        'item_type' => $item->item_type,
                        'status' => $statusLabels[$item->status] ?? str_replace('_', ' ', $item->status),
                        'time' => $item->start_time ? substr($item->start_time, 0, 5) : null,
                        'location' => $item->location,
                    ])->values();
                    $dayLabel = $cell['day'] ? $currentMonth->copy()->day($cell['day'])->translatedFormat('l, d F Y') : '';
                @endphp
                <div class="aspect-square min-h-0 overflow-hidden {{ $loop->last ? 'border-r-0' : 'border-r-2' }} border-t-2 border-black p-1.5 bg-white {{ $cell['isToday']?'ring-2 ring-inset ring-[#c0392b]':'' }}">
                    @if($cell['day'])
                    <button type="button" @click="openDay(@js($dayLabel), @js($dayPayload))" class="font-[Helvetica] font-bold text-xs {{ $cell['isToday']?'bg-[#c0392b] text-white px-1':'' }}">{{ $cell['day'] }}</button>
                    <div class="space-y-1 mt-1">
                        @foreach($cell['items']->take(3) as $plannerItem)
                        @php $chipColor = ['task'=>'#9ab6c8','agenda'=>'#e6915d','content'=>'#8c9ae0'][$plannerItem->item_type] ?? '#ccc'; @endphp
                        <button type="button" @click="openDetail({{ $plannerItem->id }})" title="{{ $plannerItem->title }}" class="block w-full truncate border border-black border-l-4 bg-white px-1 py-0.5 text-left text-[9px] leading-tight" style="border-left-color:{{ $chipColor }}">
                            @if($plannerItem->start_time)<span class="font-bold">{{ substr($plannerItem->start_time, 0, 5) }}</span> @endif{{ $plannerItem->title }}
                        </button>
                        @endforeach
                        @if($cell['items']->count() > 3)
                        <button type="button" @click="openDay(@js($dayLabel), @js($dayPayload))" class="block w-full text-left text-[9px] font-bold underline text-[#0000ee]">+{{ $cell['items']->count() - 3 }} lainnya</button>
                        @endif
                    </div>
                    @endif
                </div>
                @endforeach
            @endforeach
        </div>
    </div>

    @elseif(in_array($viewMode, ['tasks','agenda','content']))
    <div class="flex gap-3 overflow-x-auto pb-3">
        @foreach($boardColumns as $status => $columnItems)
        <section class="w-72 shrink-0 border-2 border-black bg-gray-100"><h2 class="bg-black text-white px-3 py-2 text-xs font-bold uppercase"><span>{{ $statusLabels[$status] ?? $status }}</span> · <span class="board-count">{{ $columnItems->count() }}</span></h2><div class="sortable-column p-2 space-y-2 min-h-40" data-status="{{ $status }}">@foreach($columnItems as $plannerItem) @include('crm.content-calendar._item-card') @endforeach</div></section>
        @endforeach
    </div>

    @else
    <div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th class="crm-select-col"><input type="checkbox" @change="selectAll()"></th><th>Tipe</th><th>Judul</th><th>Tanggal</th><th>Cabang</th><th>Proyek</th><th>Status</th><th>PIC</th><th class="crm-actions">Aksi</th></tr></thead><tbody>@forelse($items as $plannerItem)<tr><td class="crm-select-col"><input type="checkbox" :checked="isSelected({{ $plannerItem->id }})" @click="toggle({{ $plannerItem->id }})"></td><td>{{ strtoupper($plannerItem->item_type) }}</td><td title="{{ $plannerItem->title }}"><button @click="openDetail({{ $plannerItem->id }})" class="font-bold underline">{{ $plannerItem->title }}</button></td><td>{{ $plannerItem->scheduled_date?->format('d M Y') ?? '—' }}</td><td>{{ $plannerItem->branch?->name }}</td><td>{{ $plannerItem->project_name ?: '—' }}</td><td>{{ $statusLabels[$plannerItem->status] ?? $plannerItem->status }}</td><td>{{ $plannerItem->assignees->pluck('name')->merge($plannerItem->pic_names ?? [])->join(', ') ?: '—' }}</td><td class="crm-actions"><a href="{{ route('content-calendar.edit', ['content_calendar'=>$plannerItem->id, 'view'=>$viewMode]) }}" style="color:#0000ee;font-weight:bold;text-decoration:underline">Edit</a> <form method="POST" action="{{ route('content-calendar.destroy',$plannerItem) }}" class="inline" onsubmit="return confirm('Hapus item ini?')">@csrf @method('DELETE')<button style="color:#c0392b;font-weight:bold;text-decoration:underline">Hapus</button></form></td></tr>@empty<tr><td colspan="9" class="text-center py-8 italic">Tidak ada item.</td></tr>@endforelse</tbody></table></div><div class="mt-3">{{ $items->links() }}</div>
    @endif

    <form method="POST" x-ref="bulkForm" x-show="selectedIds.length" x-cloak class="sticky bottom-0 z-30 bg-white border-2 border-black mt-4 p-3 flex gap-2 items-center flex-wrap">@csrf<template x-for="id in selectedIds"><input type="hidden" name="ids[]" :value="id"></template><strong class="text-xs" x-text="selectedIds.length + ' item dipilih'"></strong><input name="status" placeholder="Status" class="border-2 border-black px-2 py-1 text-xs"><select name="priority" class="border-2 border-black px-2 py-1 text-xs"><option value="">Prioritas</option><option>low</option><option>medium</option><option>high</option><option>urgent</option></select><button type="button" @click="submitBulkUpdate()" class="bg-black text-white border-2 border-black px-3 py-1 text-xs">Update</button><button type="button" @click="submitBulkDelete()" class="bg-[#d77a7a] border-2 border-black px-3 py-1 text-xs">Hapus</button></form>

    <div x-show="dayOpen" x-cloak class="fixed inset-0 z-50 bg-black/70 flex items-center justify-center p-4" @keydown.escape.window="dayOpen=false">
        <div @click.away="dayOpen=false" class="bg-[#f5f0eb] border-2 border-black max-w-xl w-full max-h-[85vh] overflow-y-auto shadow-[6px_6px_0_#000]">
            <div class="sticky top-0 z-10 bg-black text-white px-4 py-2 flex items-center justify-between"><strong x-text="dayLabel"></strong><button type="button" @click="dayOpen=false">×</button></div>
            <div class="p-3 space-y-2">
                <template x-if="dayItems.length === 0"><p class="py-6 text-center italic">Tidak ada aktivitas.</p></template>
                <template x-for="item in dayItems" :key="item.id">
                    <article class="border-2 border-black bg-white p-3 flex items-start gap-3">
                        <span class="shrink-0 border border-black px-2 py-1 text-[9px] font-bold" :style="'background:' + typeColor(item.item_type)" x-text="item.item_type.toUpperCase()"></span>
                        <button type="button" @click="openDetail(item.id)" class="min-w-0 grow text-left">
                            <strong class="block truncate" x-text="item.title"></strong>
                            <span class="block text-xs text-gray-600"><span x-show="item.time" x-text="item.time + ' · '"></span><span x-text="item.status"></span><span x-show="item.location" x-text="' · ' + item.location"></span></span>
                        </button>
                        <a :href="editBaseUrl + '/' + item.id + '/edit?view=calendar'" class="shrink-0 font-bold underline" style="color:#0000ee">Edit</a>
                    </article>
                </template>
            </div>
        </div>
    </div>

    <div x-show="detailOpen" x-cloak class="fixed inset-0 z-50 bg-black/70 flex items-center justify-center p-4" @keydown.escape.window="detailOpen=false"><div @click.away="detailOpen=false" class="bg-[#f5f0eb] border-2 border-black max-w-lg w-full max-h-[85vh] overflow-y-auto shadow-[6px_6px_0_#000]"><div class="bg-black text-white px-4 py-2 flex justify-between"><strong x-text="detail?.title || 'Detail'"></strong><button @click="detailOpen=false">×</button></div><template x-if="detail"><div class="p-4 space-y-3 text-sm"><p x-show="detail.item_type !== 'content'" x-text="detail.task_detail || '—'" class="italic"></p><div class="grid grid-cols-2 gap-3"><div><b>Tipe</b><p x-text="detail.item_type"></p></div><div><b>Status</b><p x-text="statusLabel(detail.status)"></p></div><div x-show="detail.item_type !== 'content'"><b>Tanggal</b><p x-text="formatDate(detail.scheduled_date)"></p></div><div x-show="detail.item_type !== 'content'"><b>Visibilitas</b><p x-text="detail.visibility"></p></div><div x-show="detail.item_type !== 'content'"><b>Lokasi</b><p x-text="detail.location || '—'"></p></div><div><b>Platform</b><p x-text="detail.platform || '—'"></p></div><div x-show="detail.item_type === 'content'"><b>Tujuan Konten</b><p x-text="detail.tujuan_konten || '—'"></p></div><div x-show="detail.item_type === 'content'"><b>Format Konten</b><p x-text="detail.content_format || '—'"></p></div></div><div x-show="detail.item_type !== 'content'"><b>PIC</b><p x-text="[...(detail.assignees || []).map(u=>u.name), ...(detail.pic_names || [])].join(', ') || '—'"></p></div><div><b>Catatan</b><p x-text="detail.notes || '—'"></p></div><div class="flex justify-end"><a :href="editBaseUrl + '/' + detail.id + '/edit?view=' + returnView" class="border-2 border-black bg-[#b3bd95] px-3 py-1 font-bold">Edit</a></div></div></template></div></div>
</div>
@endsection

@push('scripts')
<script>
function plannerPage(ids, config) {
    return {
        allItemIds: ids || [], selectedIds: [], detailOpen: false, detail: null,
        dayOpen: false, dayLabel: '', dayItems: [],
        filterOpen: false, addOpen: false,
        filterBranch: config.branchId || '', filterProject: config.projectName || '',
        filterType: config.type || '', filterStatus: config.status || '', fixedType: config.fixedType,
        returnView: config.returnView || 'today',
        filterProjects: config.projects || [],
        init() { this.$nextTick(() => this.initSortable()); },
        filterStatuses: {
            task: [{value:'todo',label:'To Do'},{value:'in_progress',label:'In Progress'},{value:'completed',label:'Completed'},{value:'lost_track',label:'Lost Track'}],
            agenda: [{value:'planned',label:'Planned'},{value:'confirmed',label:'Confirmed'},{value:'done',label:'Done'},{value:'cancelled',label:'Cancelled'}],
            content: [{value:'idea',label:'Ide'},{value:'content_in_progress',label:'Dalam Proses'},{value:'done_editing',label:'Selesai Edit'},{value:'uploaded',label:'Di Upload'}],
        },
        detailBaseUrl: @js(url('content-calendar')), editBaseUrl: @js(url('content-calendar')),
        get filterProjectOptions() { return this.filterProjects.filter(project => !this.filterBranch || String(project.branch_id) === String(this.filterBranch)); },
        get filterStatusOptions() {
            if (this.filterType) return this.filterStatuses[this.filterType] || [];
            const seen = new Set();
            return Object.values(this.filterStatuses).flat().filter(option => !seen.has(option.value) && seen.add(option.value));
        },
        syncFilterStatus() { if (!this.filterStatusOptions.some(option => option.value === this.filterStatus)) this.filterStatus=''; },
        initSortable() {
            if (!window.Sortable) return;
            document.querySelectorAll('.sortable-column').forEach(column => {
                if (column.dataset.sortableReady) return;
                column.dataset.sortableReady = '1';
                window.Sortable.create(column, {
                    group: 'work-planner-board',
                    draggable: '.planner-board-card',
                    animation: 150,
                    ghostClass: 'opacity-50',
                    onEnd: (event) => this.updateDraggedStatus(event),
                });
            });
        },
        async updateDraggedStatus(event) {
            const itemId = event.item?.dataset?.itemId;
            const newStatus = event.to?.dataset?.status;
            const oldStatus = event.from?.dataset?.status;
            if (!itemId || !newStatus || newStatus === oldStatus) return;
            this.refreshBoardCounts();
            try {
                const response = await fetch(`${this.detailBaseUrl}/${itemId}/status`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ status: newStatus, expected_updated_at: event.item.dataset.updatedAt }),
                });
                if (response.status === 409) {
                    const conflict = await response.json();
                    const reference = event.from.children[event.oldIndex] || null;
                    event.from.insertBefore(event.item, reference);
                    this.refreshBoardCounts();
                    window.dispatchEvent(new CustomEvent('oasis-conflict', { detail: { response: conflict, context: {} } }));
                    return;
                }
                if (!response.ok) throw new Error(await response.text());
                const data = await response.json();
                event.item.dataset.updatedAt = data.updated_at;
            } catch (error) {
                const reference = event.from.children[event.oldIndex] || null;
                event.from.insertBefore(event.item, reference);
                this.refreshBoardCounts();
                alert('Status belum dapat diperbarui. Periksa koneksi lalu coba kembali.');
            }
        },
        refreshBoardCounts() {
            document.querySelectorAll('.sortable-column').forEach(column => {
                const count = column.closest('section')?.querySelector('.board-count');
                if (count) count.textContent = column.querySelectorAll('.planner-board-card').length;
            });
        },
        isSelected(id) { return this.selectedIds.includes(id); },
        toggle(id) { this.isSelected(id) ? this.selectedIds.splice(this.selectedIds.indexOf(id),1) : this.selectedIds.push(id); },
        selectAll() { this.selectedIds = this.selectedIds.length === this.allItemIds.length ? [] : [...this.allItemIds]; },
        openDetail(id) { this.detailOpen=true; fetch(this.detailBaseUrl+'/'+id+'/detail').then(r=>r.json()).then(data=>this.detail=data); },
        openDay(label, items) { this.dayLabel=label; this.dayItems=items || []; this.dayOpen=true; },
        typeColor(type) { return {task:'#9ab6c8',agenda:'#e6915d',content:'#8c9ae0'}[type] || '#ccc'; },
        formatDate(value) { return value ? new Date(value).toLocaleDateString('id-ID',{day:'numeric',month:'short',year:'numeric'}) : '—'; },
        statusLabel(value) { return Object.values(this.filterStatuses).flat().find(option => option.value === value)?.label || value || '—'; },
        submitBulkUpdate() { this.$refs.bulkForm.action=@js(route('content-calendar.bulk-update')); this.$refs.bulkForm.submit(); },
        submitBulkDelete() { if(confirm('Hapus item terpilih?')) { this.$refs.bulkForm.action=@js(route('content-calendar.bulk-delete')); this.$refs.bulkForm.submit(); } },
    };
}
</script>
@endpush
