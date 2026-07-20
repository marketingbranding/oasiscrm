@extends('layouts.crm')

@section('title', 'Proyek - Oasis CRM')

@section('content')
    <x-crm.page-header color="#5d8e8e" title="Manajemen Proyek" />

    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('projects.index') }}" class="flex items-center gap-3 flex-wrap filter-bar">
            <div class="flex items-center gap-3 flex-wrap">
            <label class="font-[Helvetica] font-bold text-xs uppercase">Pilih Cabang:</label>
            <select name="branch_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Cabang —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }} @if(str_contains(mb_strtolower($b->name), 'pusat')) style="color:#b8860b;font-weight:700;background:#fff3b0" @endif>{{ $b->name }}</option>
                @endforeach
            </select>
                <label class="font-[Helvetica] font-bold text-xs uppercase">Cari:</label>
                <input name="search" value="{{ request('search') }}"
                       placeholder="Nama Proyek..."
                       class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none"
                       onkeydown="if(event.key==='Enter') this.form.submit()">
            </div>

            <div class="h-6 w-px bg-black mx-1 hidden sm:block"></div>

            <div class="flex items-center gap-2 ml-auto">
                <a href="{{ route('projects.create') }}" class="bg-[#5d8e8e] text-white px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#4a7a7a]">
                    + Proyek Baru
                </a>
            </div>
        </form>
    </div>

    <div class="table-scroll border-2 border-black bg-white overflow-auto max-h-[calc(100vh-12rem)]">
        <table class="w-full text-sm font-['Times_New_Roman']">
            <thead>
                <tr class="bg-black text-white">
                    <x-crm.sortable-th field="project_name" route="projects.index" label="Proyek" :currentSort="$sortField" :currentDir="$sortDir" />
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Cabang</th>
                    <x-crm.sortable-th field="kavlings_count" route="projects.index" label="Kavling" :currentSort="$sortField" :currentDir="$sortDir" align="center" />
                    <x-crm.sortable-th field="is_active" route="projects.index" label="Status" :currentSort="$sortField" :currentDir="$sortDir" align="center" />
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-black">
                @forelse($projects as $p)
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 font-bold">{{ $p->project_name }}</td>
                    <td class="px-3 py-2">{{ $p->branch->name ?? '—' }}</td>
                    <td class="px-3 py-2 text-center font-bold">{{ $p->kavlings_count }}</td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black {{ $p->is_active ? 'bg-[#b3bd95]' : 'bg-gray-200' }}">
                            {{ $p->is_active ? 'AKTIF' : 'NONAKTIF' }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-center">
                        <div class="flex items-center justify-center gap-1">
                            <a href="{{ route('kavlings.index', ['project' => $p->id]) }}" class="text-xs font-[Helvetica] font-bold underline hover:text-[#5d8e8e]">Kavling</a>
                            <a href="{{ route('projects.edit', $p->id) }}" class="text-xs font-[Helvetica] font-bold underline hover:text-[#5d8e8e]">Edit</a>
                            <form method="POST" action="{{ route('projects.destroy', ['project' => $p->id]) }}"
                                  onsubmit="return confirm('Hapus proyek ini?')" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs font-[Helvetica] font-bold underline hover:text-[#e91d2a]">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-sm">Belum ada proyek.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="flex items-center justify-between px-4 py-3 border-t-2 border-black bg-white">
        <div class="text-xs font-['Times_New_Roman']">
            @if(method_exists($projects, 'total'))
                {{ $projects->firstItem() }}–{{ $projects->lastItem() }} dari {{ $projects->total() }}
            @else
                Semua {{ $projects->count() }} data
            @endif
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs font-['Times_New_Roman']">Tampilkan</span>
            <select onchange="window.location.href=this.value"
                    class="border border-black text-xs px-1 py-0.5 font-['Times_New_Roman'] bg-white">
                <option value="{{ request()->fullUrlWithQuery(['per_page' => 15]) }}" {{ ($perPage ?? '15') == '15' ? 'selected' : '' }}>15</option>
                <option value="{{ request()->fullUrlWithQuery(['per_page' => 30]) }}" {{ ($perPage ?? '15') == '30' ? 'selected' : '' }}>30</option>
                <option value="{{ request()->fullUrlWithQuery(['per_page' => 50]) }}" {{ ($perPage ?? '15') == '50' ? 'selected' : '' }}>50</option>
                <option value="{{ request()->fullUrlWithQuery(['per_page' => 100]) }}" {{ ($perPage ?? '15') == '100' ? 'selected' : '' }}>100</option>
                <option value="{{ request()->fullUrlWithQuery(['per_page' => 'all']) }}" {{ ($perPage ?? '15') == 'all' ? 'selected' : '' }}>Semua</option>
            </select>
        </div>
        <div class="flex items-center gap-1">
            @if(method_exists($projects, 'links'))
                {{ $projects->links() }}
            @endif
        </div>
    </div>
<style>
.table-scroll thead th {
    position: sticky;
    top: 0;
    z-index: 10;
    background-color: black;
    color: white;
}
</style>
@endsection
