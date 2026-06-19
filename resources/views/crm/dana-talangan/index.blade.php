@extends('layouts.crm')

@section('title', 'Dana Talangan - Oasis CRM')

@section('content')
    <div class="bg-[#f1c40f] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Dana Talangan</h1>
    </div>

    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('dana-talangan.index') }}" class="flex items-center gap-3 flex-wrap">
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
        </form>
    </div>

    <div class="flex justify-end mb-4">
        <a href="{{ route('dana-talangan.create') }}" class="bg-[#f1c40f] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#d4ac0d]">
            + Dana Talangan Baru
        </a>
    </div>

    <div class="border-2 border-black bg-white overflow-x-auto">
        <table class="w-full text-sm font-['Times_New_Roman']">
            <thead>
                <tr class="bg-black text-white">
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">No</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Tanggal</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Nama Konsumen</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Kav</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Proyek</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Pinjam Nama</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Pekerjaan</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Status Kawin</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Umur</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Marketing</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">Penyelesaian</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Konfirmasi</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Status</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-black">
                @forelse($records as $i => $r)
                <tr class="{{ $r->status === 'lunas' ? 'bg-[#b3bd95]' : '' }} dt-row">
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
                            <span class="text-[#b3bd95] font-bold">✓</span>
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
                    <td colspan="14" class="px-4 py-8 text-center text-sm">Belum ada data dana talangan.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
<style>.dt-row:hover{background:#fef3cd}</style>
@endsection
