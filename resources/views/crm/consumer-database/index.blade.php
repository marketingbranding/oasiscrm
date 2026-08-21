@extends('layouts.crm')

@section('title', $module['label'].' - Database Baru')

@section('content')
<x-crm.page-header variant="canonical" title="Database Baru" eyebrow="Database Konsumen" description="Data ConsumerApplication lokal sesuai akses cabang dan proyek." />
<nav aria-label="Modul database konsumen" class="mb-3 flex gap-1 overflow-x-auto border-b-2 border-black">
    @foreach($registry as $slug => $item)
        <a href="{{ route('consumer-database.module', ['module' => $slug, 'view' => $viewMode]) }}" @class(['min-h-11 whitespace-nowrap border-2 border-b-0 border-black px-3 py-2 text-sm font-bold', 'bg-[#fcc20f]' => $slug === $moduleSlug, 'bg-white' => $slug !== $moduleSlug]) @if($slug === $moduleSlug) aria-current="page" @endif>{{ $item['label'] }}</a>
    @endforeach
</nav>
<x-crm.toolbar label="Cari dan filter database konsumen" class="mb-3">
    <form method="GET" action="{{ route('consumer-database.module', $moduleSlug) }}" class="flex min-w-0 flex-wrap items-end gap-2">
        <input type="hidden" name="view" value="{{ $viewMode }}">
        @foreach(request()->only(['filter_column', 'filter', 'sort', 'direction']) as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
        <label class="min-w-0 flex-1 text-xs font-bold uppercase sm:min-w-[220px]">Cari<input type="search" name="search" value="{{ request('search') }}" placeholder="Nama, no HP, kavling, atau proyek" class="mt-1 block w-full min-w-0 border border-gray-300 bg-white px-3 py-2 text-sm"></label>
        <label class="w-full min-w-0 text-xs font-bold uppercase sm:w-auto">Cabang<select name="branch_id" class="mt-1 block w-full min-w-0 max-w-full border border-gray-300 bg-white px-3 py-2 text-sm sm:max-w-64"><option value="">Semua cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) request('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
        <label class="w-full min-w-0 text-xs font-bold uppercase sm:w-auto">Proyek<select name="project_id" class="mt-1 block w-full min-w-0 max-w-full border border-gray-300 bg-white px-3 py-2 text-sm sm:max-w-72"><option value="">Semua proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((string) request('project_id') === (string) $project->id)>{{ $project->project_name }}@if(!request()->filled('branch_id')) — {{ $project->branch?->name }}@endif</option>@endforeach</select></label>
        <button class="min-h-11 border-2 border-black bg-[#fcc20f] px-3 py-2 font-bold">Terapkan</button>
        <a href="{{ route('consumer-database.module', ['module' => $moduleSlug, 'view' => $viewMode]) }}" class="min-h-11 border border-gray-300 bg-white px-3 py-2 font-bold">Reset</a>
    </form>
    @if($filterColumns->isNotEmpty())
        <form method="GET" class="mt-2 flex min-w-0 flex-wrap items-end gap-2 border-t border-gray-200 pt-2">
            @foreach(request()->only(['view', 'search', 'branch_id', 'project_id', 'sort', 'direction']) as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
            <label class="w-full min-w-0 text-xs font-bold uppercase sm:w-auto">Filter kolom<select name="filter_column" class="mt-1 block w-full min-w-0 max-w-full border border-gray-300 bg-white px-3 py-2 text-sm sm:max-w-64">@foreach($filterColumns as $column)<option value="{{ $column['key'] }}" @selected(request('filter_column') === $column['key'])>{{ $column['label'] }}</option>@endforeach</select></label>
            <label class="min-w-0 flex-1 text-xs font-bold uppercase">Nilai<input name="filter" value="{{ request('filter') }}" class="mt-1 block w-full min-w-0 border border-gray-300 bg-white px-3 py-2 text-sm"></label>
            <button class="min-h-11 border border-black bg-white px-3 py-2 font-bold">Filter</button>
        </form>
    @endif
    <div class="mt-2 flex gap-1" role="group" aria-label="Tampilan data"><a href="{{ request()->fullUrlWithQuery(['view' => 'table', 'page' => null]) }}" @class(['flex min-h-11 items-center border-2 border-black px-3 py-1 font-bold', 'bg-black text-white' => $viewMode === 'table']) @if($viewMode === 'table') aria-current="page" @endif>Table</a><a href="{{ request()->fullUrlWithQuery(['view' => 'sheet', 'page' => null]) }}" @class(['flex min-h-11 items-center border-2 border-black px-3 py-1 font-bold', 'bg-black text-white' => $viewMode === 'sheet']) @if($viewMode === 'sheet') aria-current="page" @endif>Sheet</a></div>
</x-crm.toolbar>
<div class="crm-table-scroll border-2 border-black bg-white"><table class="crm-data-table min-w-full"><caption class="sr-only">{{ $module['description'] }}</caption><thead><tr>@if($viewMode === 'sheet')<th scope="col" class="sticky left-0 z-20 w-14 min-w-14 bg-black">#</th>@endif @foreach($module['columns'] as $column)@if($column['sortable'])<x-crm.click-sort-th :field="$column['key']" route="consumer-database.module" :route-parameters="['module' => $moduleSlug]" :label="$column['label']" :current-sort="request('sort')" :current-dir="request('direction')" direction-param="direction" current-indicator />@else<th scope="col">@if($viewMode === 'sheet')<span class="block text-[10px] text-gray-300">{{ $moduleRegistry->columnLetter($loop->iteration) }}</span>@endif{{ $column['label'] }}</th>@endif @endforeach</tr></thead><tbody>
@forelse($rows as $row)<tr data-source-id="{{ $row['source_id'] }}">@if($viewMode === 'sheet')<th scope="row" class="sticky left-0 z-10 w-14 min-w-14 bg-white font-bold">{{ $rows->firstItem() + $loop->index }}</th>@endif @foreach($module['columns'] as $column)@php $value = data_get($row, $column['path']); $narrative = in_array($column['key'], ['notes'], true); @endphp<td @class(['max-w-64 whitespace-normal break-words' => $narrative, 'max-w-56 truncate' => !$narrative]) title="{{ is_scalar($value) ? $value : '' }}">@if($value instanceof \Carbon\CarbonInterface){{ $value->format('d/m/Y') }}@elseif(is_bool($value)){{ $value ? 'Ya' : 'Tidak' }}@elseif($column['type'] === 'money' && is_numeric($value))Rp {{ number_format((float) $value, 0, ',', '.') }}@elseif($column['type'] === 'number' && is_numeric($value)){{ number_format((int) $value, 0, ',', '.') }}@else{{ filled($value) ? $value : '—' }}@endif</td>@endforeach</tr>
@empty<tr><td colspan="{{ count($module['columns']) + ($viewMode === 'sheet' ? 1 : 0) }}"><x-crm.empty-state :title="request()->hasAny(['search', 'branch_id', 'project_id', 'filter']) ? 'Data tidak ditemukan' : 'Belum ada data'" :description="request()->hasAny(['search', 'branch_id', 'project_id', 'filter']) ? 'Tidak ada data yang cocok dengan pencarian atau filter aktif.' : 'Belum ada data untuk modul ini.'" /></td></tr>@endforelse
</tbody></table></div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
