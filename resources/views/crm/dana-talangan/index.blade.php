@extends('layouts.crm')

@section('title', 'Dana Talangan - Oasis CRM')

@section('content')
    <div class="bg-[#f1c40f] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Dana Talangan</h1>
    </div>

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
                <a href="{{ route('dana-talangan.export', request()->only(['branch_id', 'project_name', 'status'])) }}" class="bg-white text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                    ↓ Export XLSX
                </a>
                <a href="{{ route('dana-talangan.create') }}" class="bg-[#f1c40f] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#d4ac0d]">
                    + Dana Talangan Baru
                </a>
            </div>
        </form>
    </div>

    <div class="table-scroll border-2 border-black bg-white overflow-auto max-h-[calc(100vh-12rem)]">
        <table class="w-full text-sm font-['Times_New_Roman']">
            <thead>
                <tr class="bg-black text-white">
                    <th class="w-10 px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase"><input type="checkbox" id="select-all" class="cursor-pointer"></th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">No</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">
                        @php
                            $isActive = request('sort') === 'tanggal';
                            $currentDir = request('dir');
                            if (!$isActive) {
                                $linkParams = array_merge(request()->query(), ['sort' => 'tanggal', 'dir' => 'asc']);
                                $arrow = '';
                            } elseif ($currentDir === 'asc') {
                                $linkParams = array_merge(request()->query(), ['sort' => 'tanggal', 'dir' => 'desc']);
                                $arrow = '▲';
                            } else {
                                $linkParams = collect(request()->query())->except(['sort', 'dir'])->toArray();
                                $arrow = '▼';
                            }
                        @endphp
                        <a href="{{ route('dana-talangan.index', $linkParams) }}" class="hover:underline text-white">
                            Tanggal
                            @if($isActive)<span class="ml-0.5">{{ $arrow }}</span>@endif
                        </a>
                    </th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">
                        @php
                            $isActive = request('sort') === 'nama_konsumen';
                            $currentDir = request('dir');
                            if (!$isActive) {
                                $linkParams = array_merge(request()->query(), ['sort' => 'nama_konsumen', 'dir' => 'asc']);
                                $arrow = '';
                            } elseif ($currentDir === 'asc') {
                                $linkParams = array_merge(request()->query(), ['sort' => 'nama_konsumen', 'dir' => 'desc']);
                                $arrow = '▲';
                            } else {
                                $linkParams = collect(request()->query())->except(['sort', 'dir'])->toArray();
                                $arrow = '▼';
                            }
                        @endphp
                        <a href="{{ route('dana-talangan.index', $linkParams) }}" class="hover:underline text-white">
                            Nama Konsumen
                            @if($isActive)<span class="ml-0.5">{{ $arrow }}</span>@endif
                        </a>
                    </th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">
                        @php
                            $isActive = request('sort') === 'kav';
                            $currentDir = request('dir');
                            if (!$isActive) {
                                $linkParams = array_merge(request()->query(), ['sort' => 'kav', 'dir' => 'asc']);
                                $arrow = '';
                            } elseif ($currentDir === 'asc') {
                                $linkParams = array_merge(request()->query(), ['sort' => 'kav', 'dir' => 'desc']);
                                $arrow = '▲';
                            } else {
                                $linkParams = collect(request()->query())->except(['sort', 'dir'])->toArray();
                                $arrow = '▼';
                            }
                        @endphp
                        <a href="{{ route('dana-talangan.index', $linkParams) }}" class="hover:underline text-white">
                            Kav
                            @if($isActive)<span class="ml-0.5">{{ $arrow }}</span>@endif
                        </a>
                    </th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">
                        @php
                            $isActive = request('sort') === 'project_name';
                            $currentDir = request('dir');
                            if (!$isActive) {
                                $linkParams = array_merge(request()->query(), ['sort' => 'project_name', 'dir' => 'asc']);
                                $arrow = '';
                            } elseif ($currentDir === 'asc') {
                                $linkParams = array_merge(request()->query(), ['sort' => 'project_name', 'dir' => 'desc']);
                                $arrow = '▲';
                            } else {
                                $linkParams = collect(request()->query())->except(['sort', 'dir'])->toArray();
                                $arrow = '▼';
                            }
                        @endphp
                        <a href="{{ route('dana-talangan.index', $linkParams) }}" class="hover:underline text-white">
                            Proyek
                            @if($isActive)<span class="ml-0.5">{{ $arrow }}</span>@endif
                        </a>
                    </th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Pinjam Nama</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">
                        @php
                            $isActive = request('sort') === 'pekerjaan';
                            $currentDir = request('dir');
                            if (!$isActive) {
                                $linkParams = array_merge(request()->query(), ['sort' => 'pekerjaan', 'dir' => 'asc']);
                                $arrow = '';
                            } elseif ($currentDir === 'asc') {
                                $linkParams = array_merge(request()->query(), ['sort' => 'pekerjaan', 'dir' => 'desc']);
                                $arrow = '▲';
                            } else {
                                $linkParams = collect(request()->query())->except(['sort', 'dir'])->toArray();
                                $arrow = '▼';
                            }
                        @endphp
                        <a href="{{ route('dana-talangan.index', $linkParams) }}" class="hover:underline text-white">
                            Pekerjaan
                            @if($isActive)<span class="ml-0.5">{{ $arrow }}</span>@endif
                        </a>
                    </th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">
                        @php
                            $isActive = request('sort') === 'status_perkawinan';
                            $currentDir = request('dir');
                            if (!$isActive) {
                                $linkParams = array_merge(request()->query(), ['sort' => 'status_perkawinan', 'dir' => 'asc']);
                                $arrow = '';
                            } elseif ($currentDir === 'asc') {
                                $linkParams = array_merge(request()->query(), ['sort' => 'status_perkawinan', 'dir' => 'desc']);
                                $arrow = '▲';
                            } else {
                                $linkParams = collect(request()->query())->except(['sort', 'dir'])->toArray();
                                $arrow = '▼';
                            }
                        @endphp
                        <a href="{{ route('dana-talangan.index', $linkParams) }}" class="hover:underline text-white">
                            Status Kawin
                            @if($isActive)<span class="ml-0.5">{{ $arrow }}</span>@endif
                        </a>
                    </th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">
                        @php
                            $isActive = request('sort') === 'umur';
                            $currentDir = request('dir');
                            if (!$isActive) {
                                $linkParams = array_merge(request()->query(), ['sort' => 'umur', 'dir' => 'asc']);
                                $arrow = '';
                            } elseif ($currentDir === 'asc') {
                                $linkParams = array_merge(request()->query(), ['sort' => 'umur', 'dir' => 'desc']);
                                $arrow = '▲';
                            } else {
                                $linkParams = collect(request()->query())->except(['sort', 'dir'])->toArray();
                                $arrow = '▼';
                            }
                        @endphp
                        <a href="{{ route('dana-talangan.index', $linkParams) }}" class="hover:underline text-white">
                            Umur
                            @if($isActive)<span class="ml-0.5">{{ $arrow }}</span>@endif
                        </a>
                    </th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">
                        @php
                            $isActive = request('sort') === 'nama_marketing';
                            $currentDir = request('dir');
                            if (!$isActive) {
                                $linkParams = array_merge(request()->query(), ['sort' => 'nama_marketing', 'dir' => 'asc']);
                                $arrow = '';
                            } elseif ($currentDir === 'asc') {
                                $linkParams = array_merge(request()->query(), ['sort' => 'nama_marketing', 'dir' => 'desc']);
                                $arrow = '▲';
                            } else {
                                $linkParams = collect(request()->query())->except(['sort', 'dir'])->toArray();
                                $arrow = '▼';
                            }
                        @endphp
                        <a href="{{ route('dana-talangan.index', $linkParams) }}" class="hover:underline text-white">
                            Marketing
                            @if($isActive)<span class="ml-0.5">{{ $arrow }}</span>@endif
                        </a>
                    </th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Penyelesaian</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Konfirmasi</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">
                        @php
                            $isActive = request('sort') === 'status';
                            $currentDir = request('dir');
                            if (!$isActive) {
                                $linkParams = array_merge(request()->query(), ['sort' => 'status', 'dir' => 'asc']);
                                $arrow = '';
                            } elseif ($currentDir === 'asc') {
                                $linkParams = array_merge(request()->query(), ['sort' => 'status', 'dir' => 'desc']);
                                $arrow = '▲';
                            } else {
                                $linkParams = collect(request()->query())->except(['sort', 'dir'])->toArray();
                                $arrow = '▼';
                            }
                        @endphp
                        <a href="{{ route('dana-talangan.index', $linkParams) }}" class="hover:underline text-white">
                            Status
                            @if($isActive)<span class="ml-0.5">{{ $arrow }}</span>@endif
                        </a>
                    </th>
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
    <div class="flex items-center justify-between px-4 py-3 border-t-2 border-black bg-white">
        <div class="text-xs font-['Times_New_Roman']">
            @if(method_exists($records, 'total'))
                {{ $records->firstItem() }}–{{ $records->lastItem() }} dari {{ $records->total() }}
            @else
                Semua {{ $records->count() }} data
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
            @if(method_exists($records, 'links'))
                {{ $records->links() }}
            @endif
        </div>
    </div>
<style>.dt-row:hover{background:#fef3cd}</style>

<div id="bulk-bar" class="fixed bottom-4 left-4 z-50 bg-white border-2 border-black shadow-lg hidden">
    <div class="flex items-center gap-3 px-4 py-3">
        <span class="text-sm font-[Helvetica] font-bold"><span id="bulk-count">0</span> data terpilih</span>
        <div class="h-6 w-px bg-black mx-1"></div>
        <select id="bulk-new-status" class="border-2 border-black px-2 py-1.5 text-xs font-['Times_New_Roman'] bg-white">
            <option value="aktif">→ Aktif</option>
            <option value="lunas">→ Lunas</option>
        </select>
        <button onclick="bulkUpdateStatus()" class="bg-[#f1c40f] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#e0b80e] cursor-pointer">
            Update Status
        </button>
        <div class="h-6 w-px bg-black mx-1"></div>
        <button onclick="bulkDelete()" class="bg-[#e91d2a] text-white px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#c0392b] cursor-pointer">
            Hapus Terpilih
        </button>
    </div>
</div>

<form id="bulk-form" method="POST" action="{{ route('dana-talangan.bulk-destroy') }}" class="hidden">
    @csrf
    <input type="hidden" name="selected_ids" id="bulk-ids">
    @foreach(request()->only(['branch_id', 'project_name', 'status']) as $k => $v)
        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
    @endforeach
</form>

<form id="bulk-update-form" method="POST" action="{{ route('dana-talangan.bulk-update') }}" class="hidden">
    @csrf
    <input type="hidden" name="selected_ids" id="bulk-update-ids">
    <input type="hidden" name="new_status" id="bulk-update-status">
    @foreach(request()->only(['branch_id', 'project_name', 'status']) as $k => $v)
        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
    @endforeach
</form>

<script>
let selected = new Set();
document.getElementById('select-all')?.addEventListener('change', function() {
    document.querySelectorAll('.row-checkbox').forEach(cb => {
        cb.checked = this.checked;
        this.checked ? selected.add(cb.value) : selected.delete(cb.value);
    });
    toggleBulkBar();
});
document.querySelectorAll('.row-checkbox').forEach(cb => {
    cb.addEventListener('change', function() {
        this.checked ? selected.add(this.value) : selected.delete(this.value);
        toggleBulkBar();
    });
});
function toggleBulkBar() {
    const bar = document.getElementById('bulk-bar');
    const count = selected.size;
    document.getElementById('bulk-count').textContent = count;
    bar.style.display = count > 0 ? 'block' : 'none';
}
function bulkDelete() {
    const count = selected.size;
    if (!count) return;
    if (!confirm('Hapus ' + count + ' data terpilih?')) return;
    document.getElementById('bulk-ids').value = Array.from(selected).join(',');
    document.getElementById('bulk-form').submit();
}
function bulkUpdateStatus() {
    const count = selected.size;
    if (!count) return;
    const status = document.getElementById('bulk-new-status').value;
    if (!confirm('Ubah status ' + count + ' data terpilih menjadi ' + status + '?')) return;
    document.getElementById('bulk-update-ids').value = Array.from(selected).join(',');
    document.getElementById('bulk-update-status').value = status;
    document.getElementById('bulk-update-form').submit();
}
</script>
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
