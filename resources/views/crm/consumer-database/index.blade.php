@extends('layouts.crm')

@section('title', $module['label'].' - Database Baru')

@section('content')
<x-crm.page-header variant="canonical" title="Database Baru" eyebrow="Database Konsumen" description="Data ConsumerApplication lokal sesuai akses cabang dan proyek." />
<nav aria-label="Modul database konsumen" class="mb-3 flex gap-1 overflow-x-auto border-b-2 border-black">
    @foreach($registry as $slug => $item)
        <a href="{{ route('consumer-database.module', ['module' => $slug] + request()->except('page')) }}" @class(['whitespace-nowrap border-2 border-b-0 border-black px-3 py-2 text-sm font-bold', 'bg-[#fcc20f]' => $slug === $moduleSlug, 'bg-white' => $slug !== $moduleSlug])>{{ $item['label'] }}</a>
    @endforeach
</nav>
<x-crm.toolbar label="Cari dan filter database konsumen" class="mb-3">
    <form method="GET" action="{{ route('consumer-database.module', $moduleSlug) }}" class="flex flex-wrap items-end gap-2">
        <input type="hidden" name="view" value="{{ $viewMode }}">
        <label class="min-w-[220px] flex-1 text-xs font-bold uppercase">Cari<input type="search" name="search" value="{{ request('search') }}" placeholder="Nama, no HP, kavling, atau proyek" class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2 text-sm"></label>
        <label class="text-xs font-bold uppercase">Cabang<select name="branch_id" class="mt-1 block border border-gray-300 bg-white px-3 py-2 text-sm"><option value="">Semua cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) request('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
        <label class="text-xs font-bold uppercase">Proyek<select name="project_id" class="mt-1 block border border-gray-300 bg-white px-3 py-2 text-sm"><option value="">Semua proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((string) request('project_id') === (string) $project->id)>{{ $project->project_name }}@if(!request()->filled('branch_id')) — {{ $project->branch?->name }}@endif</option>@endforeach</select></label>
        <button class="border-2 border-black bg-[#fcc20f] px-3 py-2 font-bold">Terapkan</button>
        <a href="{{ route('consumer-database.module', $moduleSlug) }}" class="border border-gray-300 bg-white px-3 py-2 font-bold">Reset</a>
    </form>
    @if($filterColumns->isNotEmpty())<form method="GET" class="mt-2 flex flex-wrap items-end gap-2 border-t border-gray-200 pt-2"><input type="hidden" name="view" value="{{ $viewMode }}"><input type="hidden" name="search" value="{{ request('search') }}"><input type="hidden" name="branch_id" value="{{ request('branch_id') }}"><input type="hidden" name="project_id" value="{{ request('project_id') }}"><label class="text-xs font-bold uppercase">Filter kolom<select name="filter_column" class="mt-1 block border border-gray-300 bg-white px-3 py-2 text-sm">@foreach($filterColumns as $column)<option value="{{ $column['key'] }}" @selected(request('filter_column') === $column['key'])>{{ $column['label'] }}</option>@endforeach</select></label><label class="text-xs font-bold uppercase">Nilai<input name="filter" value="{{ request('filter') }}" class="mt-1 block border border-gray-300 bg-white px-3 py-2 text-sm"></label><button class="border border-black bg-white px-3 py-2 font-bold">Filter</button></form>@endif
    <div class="mt-2 flex gap-1" role="group" aria-label="Tampilan data"><a href="{{ request()->fullUrlWithQuery(['view' => 'table', 'page' => null]) }}" @class(['border-2 border-black px-3 py-1 font-bold', 'bg-black text-white' => $viewMode === 'table'])>Table</a><a href="{{ request()->fullUrlWithQuery(['view' => 'sheet', 'page' => null]) }}" @class(['border-2 border-black px-3 py-1 font-bold', 'bg-black text-white' => $viewMode === 'sheet'])>Sheet</a></div>
</x-crm.toolbar>
<div class="crm-table-scroll border-2 border-black bg-white"><table class="crm-data-table min-w-full"><thead><tr>@if($viewMode === 'sheet')<th class="sticky left-0">#</th>@endif @foreach($module['columns'] as $column)<th>@if($viewMode === 'sheet')<span class="block text-[10px] text-gray-300">{{ $moduleRegistry->columnLetter($loop->iteration) }}</span>@endif{{ $column['label'] }}</th>@endforeach</tr></thead><tbody>
@forelse($rows as $row)<tr data-source-id="{{ $row['source_id'] }}">@if($viewMode === 'sheet')<td class="sticky left-0 bg-white font-bold">{{ $rows->firstItem() + $loop->index }}</td>@endif @foreach($module['columns'] as $column)@php $value = data_get($row, $column['path']); @endphp<td title="{{ is_scalar($value) ? $value : '' }}">@if($value instanceof \Carbon\CarbonInterface){{ $value->format('d/m/Y') }}@elseif(is_bool($value)){{ $value ? 'Ya' : 'Tidak' }}@else{{ filled($value) ? $value : '—' }}@endif</td>@endforeach</tr>
@empty<tr><td colspan="{{ count($module['columns']) + ($viewMode === 'sheet' ? 1 : 0) }}"><x-crm.empty-state title="Belum ada data" description="Tidak ada data sesuai modul dan filter aktif." /></td></tr>@endforelse
</tbody></table></div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
