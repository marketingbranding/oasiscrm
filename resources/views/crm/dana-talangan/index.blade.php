@extends('layouts.crm')

@section('title', 'Dana Talangan - Oasis CRM')

@section('content')
    <x-crm.page-header color="#f1c40f" title="Dana Talangan" />

    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('dana-talangan.index') }}" class="flex items-center gap-3 flex-wrap filter-bar">
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
                <option value="aktif" {{ $selectedStatus === 'aktif' ? 'selected' : '' }}>Aktif</option>
                <option value="lunas" {{ $selectedStatus === 'lunas' ? 'selected' : '' }}>Lunas</option>
            </select>
                <label class="font-[Helvetica] font-bold text-xs uppercase">Cari:</label>
                <input name="search" value="{{ request('search') }}"
                       placeholder="Nama, Kav, Proyek..."
                       class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none"
                       onkeydown="if(event.key==='Enter') this.form.submit()">
            </div>

            <div class="h-6 w-px bg-black mx-1 hidden sm:block"></div>

            <div class="flex items-center gap-2 ml-auto">
                <x-crm.export-import export-route="dana-talangan.export" import-route="dana-talangan.import" :params="request()->only(['branch_id', 'project_name', 'status'])" />
                <a href="{{ route('dana-talangan.create') }}" class="bg-[#f1c40f] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#d4ac0d]">
                    + Dana Talangan Baru
                </a>
            </div>
        </form>
    </div>

    <div class="table-scroll border-2 border-black bg-white overflow-auto max-h-[calc(100vh-12rem)]">
        <table class="min-w-full text-sm font-['Times_New_Roman']">
            <thead>
                <tr class="bg-black text-white">
                    <th class="w-10 px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase"><input type="checkbox" id="select-all" class="cursor-pointer"></th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">No</th>
                    <x-crm.sortable-th field="tanggal" route="dana-talangan.index" label="Tanggal" :currentSort="$sortField" :currentDir="$sortDir" />
                    <x-crm.sortable-th field="nama_konsumen" route="dana-talangan.index" label="Nama Konsumen" :currentSort="$sortField" :currentDir="$sortDir" />
                    <x-crm.sortable-th field="kav" route="dana-talangan.index" label="Kav" :currentSort="$sortField" :currentDir="$sortDir" classes="hidden lg:table-cell" />
                    <x-crm.sortable-th field="project_name" route="dana-talangan.index" label="Proyek" :currentSort="$sortField" :currentDir="$sortDir" classes="hidden lg:table-cell" />
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase hidden lg:table-cell">Pinjam Nama</th>
                    <x-crm.sortable-th field="pekerjaan" route="dana-talangan.index" label="Pekerjaan" :currentSort="$sortField" :currentDir="$sortDir" classes="hidden lg:table-cell" />
                    <x-crm.sortable-th field="status_perkawinan" route="dana-talangan.index" label="Status Kawin" :currentSort="$sortField" :currentDir="$sortDir" classes="hidden lg:table-cell" />
                    <x-crm.sortable-th field="umur" route="dana-talangan.index" label="Umur" :currentSort="$sortField" :currentDir="$sortDir" align="center" classes="hidden lg:table-cell" />
                    <x-crm.sortable-th field="nama_marketing" route="dana-talangan.index" label="Marketing" :currentSort="$sortField" :currentDir="$sortDir" classes="hidden lg:table-cell" />
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase hidden lg:table-cell">Penyelesaian</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase hidden lg:table-cell">Konfirmasi</th>
                    <x-crm.sortable-th field="status" route="dana-talangan.index" label="Status" :currentSort="$sortField" :currentDir="$sortDir" align="center" />
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-black">
                @forelse($records as $i => $r)
                <tr class="{{ $r->status === 'lunas' ? 'bg-[#b3bd95]' : '' }} dt-row">
                    <td class="w-10 px-3 py-2 text-center"><input type="checkbox" class="row-checkbox cursor-pointer" value="{{ $r->id }}"></td>
                    <td class="px-3 py-2">{{ $i + 1 }}</td>
                    <td class="px-3 py-2">{{ $r->tanggal->format('d M Y') }}</td>
                    <td class="px-3 py-2 font-bold">{{ $r->nama_konsumen }}</td>
                    <td class="px-3 py-2">{{ $r->kav ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $r->project_name ?? '—' }}</td>
                    <td class="px-3 py-2 text-center">{{ $r->pinjam_nama ? 'YA' : 'TIDAK' }}</td>
                    <td class="px-3 py-2">{{ $r->pekerjaan ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $r->status_perkawinan ?? '—' }}</td>
                    <td class="px-3 py-2 text-center">{{ $r->umur ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $r->nama_marketing ?? '—' }}</td>
                    <td class="px-3 py-2 max-w-[200px] truncate" title="{{ $r->penyelesaian }}">{{ $r->penyelesaian ?? '—' }}</td>
                    <td class="px-3 py-2 text-center">
                        @if($r->konfirmasi_keuangan)
                            <span class="text-green-800 font-bold">✓</span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black {{ $r->status === 'lunas' ? 'bg-white' : 'bg-[#9ab6c8]' }}">
                            {{ strtoupper($r->status) }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-center">
                        <div class="flex items-center justify-center gap-1">
                            <a href="{{ route('dana-talangan.edit', $r->id) }}" class="text-xs font-[Helvetica] font-bold underline hover:text-[#f1c40f]">Edit</a>
                            <form method="POST" action="{{ route('dana-talangan.destroy', ['dana_talangan' => $r->id]) }}"
                                  onsubmit="return confirm('Hapus data ini?')" class="inline">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
                                <input type="hidden" name="project_name" value="{{ request('project_name') }}">
                                <input type="hidden" name="status" value="{{ request('status') }}">
                                <button type="submit" class="text-xs font-[Helvetica] font-bold underline hover:text-[#e91d2a]">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="15" class="px-4 py-8 text-center text-sm">Belum ada data dana talangan.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-crm.pagination :collection="$records" :per-page="$perPage" />

    <x-crm.bulk-bar
        destroy-route="{{ route('dana-talangan.bulk-destroy') }}"
        update-route="{{ route('dana-talangan.bulk-update') }}"
        :status-options="['aktif' => 'Aktif', 'lunas' => 'Lunas']"
        status-label="Status"
        accent-color="#f1c40f"
        :params="request()->only(['branch_id', 'project_name', 'status'])" />

<style>.dt-row:hover{background:#fef3cd}</style>
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
