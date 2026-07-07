@extends('layouts.crm')

@section('title', 'Leads - Oasis CRM')

@section('content')
<x-crm.page-header color="#e6915d" title="Leads" />

<div class="bg-white border-2 border-black p-3 mb-6">
    <form method="GET" action="{{ route('leads.index') }}" class="flex items-center gap-3 flex-wrap filter-bar">
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

        <label class="font-[Helvetica] font-bold text-xs uppercase">Proyek:</label>
        <select name="proyek" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
            <option value="">— Semua Proyek —</option>
            @foreach($projects as $p)
                <option value="{{ $p->project_name }}" {{ $selectedProjectName === $p->project_name ? 'selected' : '' }}>{{ $p->project_name }}</option>
            @endforeach
        </select>
            <label class="font-[Helvetica] font-bold text-xs uppercase">Cari:</label>
            <input name="search" value="{{ request('search') }}"
                   placeholder="ID Lead, Nama, No HP..."
                   class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none"
                   onkeydown="if(event.key==='Enter') this.form.submit()">
        </div>

        <div class="h-6 w-px bg-black mx-1 hidden sm:block"></div>

        <div class="flex items-center gap-2 ml-auto">
            <a href="{{ route('leads.export', array_filter(request()->only(['branch_id', 'proyek']))) }}"
               class="bg-[#b3bd95] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#9eaa7a]">
                Export CSV
            </a>
            <a href="{{ route('leads.create') }}" class="bg-[#e6915d] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#d4854f]">
                + Lead Baru
            </a>
        </div>
    </form>
</div>

<div class="table-scroll border-2 border-black bg-white overflow-auto max-h-[calc(100vh-12rem)]">
    <table class="min-w-full text-sm font-['Times_New_Roman']">
        <thead>
            <tr class="bg-black text-white">
                <th class="w-10 px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase"><input type="checkbox" id="select-all" class="cursor-pointer"></th>
                <x-crm.sortable-th field="id_lead" route="leads.index" label="ID Lead" :currentSort="$sortField" :currentDir="$sortDir" />
                <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase hidden lg:table-cell">ID Promo</th>
                <x-crm.sortable-th field="tanggal_lead" route="leads.index" label="Tgl Lead" :currentSort="$sortField" :currentDir="$sortDir" type="date" />
                <x-crm.sortable-th field="sumber" route="leads.index" label="Sumber" :currentSort="$sortField" :currentDir="$sortDir" classes="hidden lg:table-cell" />
                <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase hidden lg:table-cell">Platform</th>
                <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase hidden lg:table-cell">Campaign</th>
                <x-crm.sortable-th field="nama_konsumen" route="leads.index" label="Nama Konsumen" :currentSort="$sortField" :currentDir="$sortDir" />
                <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase hidden lg:table-cell">No HP</th>
                <x-crm.sortable-th field="proyek" route="leads.index" label="Proyek" :currentSort="$sortField" :currentDir="$sortDir" classes="hidden lg:table-cell" />
                <x-crm.sortable-th field="sales_pic" route="leads.index" label="Sales PIC" :currentSort="$sortField" :currentDir="$sortDir" classes="hidden lg:table-cell" />
                <x-crm.sortable-th field="status_lead" route="leads.index" label="Status" :currentSort="$sortField" :currentDir="$sortDir" align="center" />
                <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase hidden xl:table-cell">Keterangan</th>
                <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Aksi</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-black">
            @forelse($leads as $lead)
            <tr class="hover:bg-gray-50">
                <td class="w-10 px-3 py-2 text-center"><input type="checkbox" class="row-checkbox cursor-pointer" value="{{ $lead->id }}"></td>
                <td class="px-3 py-2 font-bold text-xs">{{ $lead->id_lead }}</td>
                <td class="px-3 py-2 hidden lg:table-cell">{{ $lead->id_promo ?? '—' }}</td>
                <td class="px-3 py-2 whitespace-nowrap">{{ $lead->tanggal_lead->format('d M Y') }}</td>
                <td class="px-3 py-2 hidden lg:table-cell">{{ $lead->sumber }}</td>
                <td class="px-3 py-2 hidden lg:table-cell">{{ $lead->platform }}</td>
                <td class="px-3 py-2 hidden lg:table-cell">{{ $lead->campaign }}</td>
                <td class="px-3 py-2 font-bold">{{ $lead->nama_konsumen }}</td>
                <td class="px-3 py-2 hidden lg:table-cell">{{ $lead->no_hp ?? '—' }}</td>
                <td class="px-3 py-2 hidden lg:table-cell">{{ $lead->proyek }}</td>
                <td class="px-3 py-2 hidden lg:table-cell">{{ $lead->sales_pic }}</td>
                <td class="px-3 py-2 text-center">
                    <span class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black bg-[#9ab6c8]">
                        {{ strtoupper($lead->status_lead) }}
                    </span>
                </td>
                <td class="px-3 py-2 hidden xl:table-cell max-w-[200px] truncate" title="{{ $lead->keterangan }}">{{ $lead->keterangan ?? '—' }}</td>
                <td class="px-3 py-2 text-center">
                    <div class="flex items-center justify-center gap-1">
                        <a href="{{ route('leads.edit', $lead->id) }}" class="text-xs font-[Helvetica] font-bold underline hover:text-[#e6915d]">Edit</a>
                        <form method="POST" action="{{ route('leads.destroy', ['lead' => $lead->id]) }}"
                              onsubmit="return confirm('Hapus lead ini?')" class="inline">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
                            <input type="hidden" name="proyek" value="{{ request('proyek') }}">
                            <button type="submit" class="text-xs font-[Helvetica] font-bold underline hover:text-[#e91d2a]">Hapus</button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="14" class="px-4 py-8 text-center text-sm">Belum ada data lead.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-crm.pagination :collection="$leads" :per-page="$perPage" />

<x-crm.bulk-bar
    destroy-route="{{ route('leads.bulk-destroy') }}"
    :params="request()->only(['branch_id', 'proyek'])" />

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
