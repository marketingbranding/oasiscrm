@extends('layouts.crm')

@section('title', 'Dana Talangan - Oasis CRM')

@section('content')
@php
    $oldEditingRecord = old('form_mode') === 'edit' ? [
        'id' => old('record_id'),
        'tanggal' => old('tanggal'),
        'nama_konsumen' => old('nama_konsumen'),
        'kav' => old('kav'),
        'project_name' => old('project_name'),
        'pinjam_nama' => old('pinjam_nama', false),
        'pekerjaan' => old('pekerjaan'),
        'status_perkawinan' => old('status_perkawinan'),
        'umur' => old('umur'),
        'nama_marketing' => old('nama_marketing'),
        'tgl_komitmen' => old('tgl_komitmen'),
        'penyelesaian' => old('penyelesaian'),
        'konfirmasi_keuangan' => old('konfirmasi_keuangan', false),
        'branch_id' => old('branch_id'),
        'status' => old('status', 'sanggup'),
    ] : null;
@endphp
<div x-data="{
    ...crmDetailModal('/dana-talangan', '/dana-talangan', {sanggup:'#9ab6c8',tidak_sanggup:'#d77a7a',lunas:'#b3bd95'}),
    adding: {{ $errors->any() && old('form_mode') !== 'edit' ? 'true' : 'false' }},
    editingRecord: @js($oldEditingRecord),
    openEdit(record) { this.editingRecord = record; },
}">
    <x-crm.page-header color="#f1c40f" title="Dana Talangan" />

    <div class="flex items-end gap-1 overflow-x-auto mb-4 border-b-2 border-black tabs-scroll">
        @foreach($monthTabs as $month)
        <a href="{{ route('dana-talangan.index', array_merge(request()->except(['page', 'month']), ['month' => $month])) }}"
           class="shrink-0 border-2 border-b-0 border-black px-4 py-2 text-xs font-[Helvetica] font-bold uppercase {{ $selectedMonth === $month ? 'bg-[#f1c40f]' : 'bg-white hover:bg-gray-100' }}">
            {{ $month }}
        </a>
        @endforeach
    </div>

    <div class="border-2 border-black bg-white px-4 py-3 mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="font-['Times_New_Roman'] text-sm">
            <strong>Status Sync:</strong>
            @if($syncStatus?->finished_at)
                {{ ucfirst($syncStatus->status) }} · {{ $syncStatus->finished_at->format('d M Y H:i') }}
            @else
                Belum pernah sync
            @endif
        </div>
        <div class="flex items-center gap-2">
            <a href="https://docs.google.com/spreadsheets/d/{{ config('services.google_sheets.dana_talangan_spreadsheet_id') }}" target="_blank"
               class="border-2 border-black bg-white px-3 py-1.5 text-xs font-[Helvetica] font-bold hover:bg-gray-100">Buka Sheet</a>
            <form method="POST" action="{{ route('dana-talangan.sync') }}">
                @csrf
                <button class="border-2 border-black bg-[#5d8e8e] text-white px-3 py-1.5 text-xs font-[Helvetica] font-bold hover:bg-[#4d7b7b]">Sync Sekarang</button>
            </form>
        </div>
    </div>

    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('dana-talangan.index') }}" class="flex items-center gap-3 flex-wrap filter-bar">
            <input type="hidden" name="month" value="{{ $selectedMonth }}">
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

            <label class="font-[Helvetica] font-bold text-xs uppercase">Status Cicilan:</label>
            <select name="status" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Status —</option>
                <option value="sanggup" {{ $selectedStatus === 'sanggup' ? 'selected' : '' }}>Sanggup</option>
                <option value="tidak_sanggup" {{ $selectedStatus === 'tidak_sanggup' ? 'selected' : '' }}>Tidak Sanggup</option>
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
                <x-crm.export-import export-route="dana-talangan.export" import-route="dana-talangan.import" :params="request()->only(['branch_id', 'project_name', 'status', 'month'])" />
                <button type="button" @click="adding = true" class="bg-[#f1c40f] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#d4ac0d]">
                    + Dana Talangan Baru
                </button>
            </div>
        </form>
    </div>

    <div class="table-scroll border-2 border-black bg-white overflow-auto max-h-[calc(100vh-12rem)]">
        <table class="min-w-full text-sm font-['Times_New_Roman']">
            <thead>
                <tr class="bg-black text-white">
                    <th class="w-10 px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase"><input type="checkbox" id="select-all" class="cursor-pointer"></th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">No</th>
                    <x-crm.sortable-th field="tanggal" route="dana-talangan.index" label="Tanggal" :currentSort="$sortField" :currentDir="$sortDir" type="date" />
                    <x-crm.sortable-th field="nama_konsumen" route="dana-talangan.index" label="Nama Konsumen" :currentSort="$sortField" :currentDir="$sortDir" />
                    <x-crm.sortable-th field="kav" route="dana-talangan.index" label="Kav" :currentSort="$sortField" :currentDir="$sortDir" classes="hidden lg:table-cell" />
                    <x-crm.sortable-th field="project_name" route="dana-talangan.index" label="Proyek" :currentSort="$sortField" :currentDir="$sortDir" classes="hidden lg:table-cell" />
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase hidden lg:table-cell">Pinjam Nama</th>
                    <x-crm.sortable-th field="pekerjaan" route="dana-talangan.index" label="Pekerjaan" :currentSort="$sortField" :currentDir="$sortDir" classes="hidden lg:table-cell" />
                    <x-crm.sortable-th field="status_perkawinan" route="dana-talangan.index" label="Status Kawin" :currentSort="$sortField" :currentDir="$sortDir" classes="hidden lg:table-cell" />
                    <x-crm.sortable-th field="umur" route="dana-talangan.index" label="Umur" :currentSort="$sortField" :currentDir="$sortDir" align="center" classes="hidden lg:table-cell" />
                    <x-crm.sortable-th field="nama_marketing" route="dana-talangan.index" label="Marketing" :currentSort="$sortField" :currentDir="$sortDir" classes="hidden lg:table-cell" />
                    <x-crm.sortable-th field="tgl_komitmen" route="dana-talangan.index" label="TGL Komitmen" :currentSort="$sortField" :currentDir="$sortDir" classes="hidden lg:table-cell" />
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase hidden lg:table-cell">Progress Penagihan</th>
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase hidden lg:table-cell">Konfirmasi</th>
                    <x-crm.sortable-th field="status" route="dana-talangan.index" label="Status Cicilan" :currentSort="$sortField" :currentDir="$sortDir" align="center" />
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-black">
                @forelse($records as $i => $r)
                <tr class="{{ $r->status === 'lunas' ? 'bg-[#b3bd95]' : ($r->status === 'tidak_sanggup' ? 'bg-[#d77a7a]' : '') }} dt-row">
                    <td class="w-10 px-3 py-2 text-center"><input type="checkbox" class="row-checkbox cursor-pointer" value="{{ $r->id }}"></td>
                    <td class="px-3 py-2">{{ $i + 1 }}</td>
                    <td class="px-3 py-2">{{ $r->tanggal->format('d M Y') }}</td>
                    <td class="px-3 py-2 font-bold cursor-pointer hover:underline" @click.prevent="openDetail({{ $r->id }})">{{ $r->nama_konsumen }}</td>
                    <td class="px-3 py-2 hidden lg:table-cell">{{ $r->kav ?? '—' }}</td>
                    <td class="px-3 py-2 hidden lg:table-cell">{{ $r->project_name ?? '—' }}</td>
                    <td class="px-3 py-2 text-center hidden lg:table-cell">{{ $r->pinjam_nama ? 'YA' : 'TIDAK' }}</td>
                    <td class="px-3 py-2 hidden lg:table-cell">{{ $r->pekerjaan ?? '—' }}</td>
                    <td class="px-3 py-2 hidden lg:table-cell">{{ $r->status_perkawinan ?? '—' }}</td>
                    <td class="px-3 py-2 text-center hidden lg:table-cell">{{ $r->umur ?? '—' }}</td>
                    <td class="px-3 py-2 hidden lg:table-cell">{{ $r->nama_marketing ?? '—' }}</td>
                    <td class="px-3 py-2 hidden lg:table-cell">{{ $r->tgl_komitmen ? \Carbon\Carbon::parse($r->tgl_komitmen)->format('d M Y') : '—' }}</td>
                    <td class="px-3 py-2 max-w-[200px] truncate hidden lg:table-cell" title="{{ $r->penyelesaian }}">{{ $r->penyelesaian ?? '—' }}</td>
                    <td class="px-3 py-2 text-center hidden lg:table-cell">
                        @if($r->konfirmasi_keuangan)
                            <span class="text-green-800 font-bold">✓</span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black {{ $r->status === 'lunas' ? 'bg-white' : ($r->status === 'tidak_sanggup' ? 'bg-[#d77a7a] text-white' : 'bg-[#9ab6c8]') }}">
                            {{ $r->status === 'sanggup' ? 'SANGGUP' : ($r->status === 'tidak_sanggup' ? 'TIDAK SANGGUP' : 'LUNAS') }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-center">
                        <div class="flex items-center justify-center gap-1">
                            <button type="button" @click='openEdit(@js([
                                "id" => $r->id,
                                "tanggal" => $r->tanggal?->format("Y-m-d"),
                                "nama_konsumen" => $r->nama_konsumen,
                                "kav" => $r->kav,
                                "project_name" => $r->project_name,
                                "pinjam_nama" => $r->pinjam_nama,
                                "pekerjaan" => $r->pekerjaan,
                                "status_perkawinan" => $r->status_perkawinan,
                                "umur" => $r->umur,
                                "nama_marketing" => $r->nama_marketing,
                                "tgl_komitmen" => $r->tgl_komitmen?->format("Y-m-d"),
                                "penyelesaian" => $r->penyelesaian,
                                "konfirmasi_keuangan" => $r->konfirmasi_keuangan,
                                "branch_id" => $r->branch_id,
                                "status" => $r->status,
                            ]))' class="text-xs font-[Helvetica] font-bold underline hover:text-[#f1c40f]">Edit</button>
                            <form method="POST" action="{{ route('dana-talangan.destroy', ['dana_talangan' => $r->id]) }}"
                                  onsubmit="return confirm('Hapus data ini?')" class="inline">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
                                <input type="hidden" name="project_name" value="{{ request('project_name') }}">
                                 <input type="hidden" name="status" value="{{ request('status') }}">
                                <input type="hidden" name="month" value="{{ $selectedMonth }}">
                                <button type="submit" class="text-xs font-[Helvetica] font-bold underline hover:text-[#e91d2a]">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="16" class="px-4 py-8 text-center text-sm">Belum ada data dana talangan.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-crm.pagination :collection="$records" :per-page="$perPage" />

    {{-- Add Modal --}}
    <div x-cloak x-show="adding" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4"
         @keydown.escape.window="adding = false">
        <div @click.away="adding = false" class="w-full max-w-4xl border-2 border-black bg-white p-5 shadow-[8px_8px_0_0_#000] max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-[Helvetica] font-bold text-sm uppercase">Tambah Dana Talangan — {{ $selectedMonth }}</h2>
                <button type="button" @click="adding = false" class="text-lg font-bold">&times;</button>
            </div>
            <form method="POST" action="{{ route('dana-talangan.store') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="form_mode" value="add">
                <input type="hidden" name="month" value="{{ $selectedMonth }}">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tanggal</label>
                        <div class="date-wrapper" data-accent="#f1c40f" style="position:relative">
                            <div class="date-display w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between" tabindex="0">
                                <span class="date-text">— Pilih Tanggal —</span><span class="date-arrow">▼</span>
                            </div>
                            <input type="date" name="tanggal" value="{{ old('tanggal', now()->format('Y-m-d')) }}" required
                                   style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                        </div>
                    </div>
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Nama Konsumen</label>
                        <input name="nama_konsumen" value="{{ old('nama_konsumen') }}" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman']">
                    </div>
                    @if(Auth::user()->canViewAllBranches())
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang</label>
                        <select name="branch_id" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white">
                            <option value="">— Pilih Cabang —</option>
                            @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" {{ old('branch_id', $selectedBranchId) == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endif
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek</label>
                        <select name="project_name" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white">
                            <option value="">— Pilih Proyek —</option>
                            @foreach($projects as $project)
                            <option value="{{ $project->project_name }}" {{ old('project_name', $selectedProject) === $project->project_name ? 'selected' : '' }}>{{ $project->project_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Kav</label>
                        <select name="kav" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white">
                            <option value="">— Pilih Kav —</option>
                            @foreach($kavlings as $kavling)
                            <option value="{{ $kavling->kavling_code }}" {{ old('kav') === $kavling->kavling_code ? 'selected' : '' }}>{{ $kavling->kavling_code }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Marketing</label>
                        <input name="nama_marketing" value="{{ old('nama_marketing') }}" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman']">
                    </div>
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Pekerjaan</label>
                        <input name="pekerjaan" value="{{ old('pekerjaan') }}" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman']">
                    </div>
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Status Kawin</label>
                        <input name="status_perkawinan" value="{{ old('status_perkawinan') }}" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman']">
                    </div>
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Umur</label>
                        <input type="number" name="umur" value="{{ old('umur') }}" min="0" max="150" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman']">
                    </div>
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">TGL Komitmen</label>
                        <div class="date-wrapper" data-accent="#f1c40f" style="position:relative">
                            <div class="date-display w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between" tabindex="0">
                                <span class="date-text">— Pilih Tanggal —</span><span class="date-arrow">▼</span>
                            </div>
                            <input type="date" name="tgl_komitmen" value="{{ old('tgl_komitmen') }}"
                                   style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                        </div>
                    </div>
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Status Cicilan</label>
                        <select name="status" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white">
                            <option value="sanggup">Sanggup</option><option value="tidak_sanggup">Tidak Sanggup</option><option value="lunas">Lunas</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-6 pt-5">
                        <label class="flex items-center gap-2 font-['Times_New_Roman'] text-sm"><input type="hidden" name="pinjam_nama" value="0"><input type="checkbox" name="pinjam_nama" value="1" class="w-5 h-5 accent-[#f1c40f]"> Pinjam Nama</label>
                        <label class="flex items-center gap-2 font-['Times_New_Roman'] text-sm"><input type="hidden" name="konfirmasi_keuangan" value="0"><input type="checkbox" name="konfirmasi_keuangan" value="1" class="w-5 h-5 accent-[#f1c40f]"> Konfirmasi</label>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Penyelesaian</label>
                        <textarea name="penyelesaian" rows="3" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman']">{{ old('penyelesaian') }}</textarea>
                    </div>
                </div>
                @if($errors->any())
                <div class="border-2 border-black bg-[#d77a7a] px-3 py-2 text-sm font-['Times_New_Roman']">{{ $errors->first() }}</div>
                @endif
                <div class="flex gap-2"><button class="bg-black text-white border-2 border-black px-6 py-2 text-sm font-[Helvetica] font-bold">Simpan</button><button type="button" @click="adding = false" class="bg-white border-2 border-black px-6 py-2 text-sm font-[Helvetica] font-bold">Batal</button></div>
            </form>
        </div>
    </div>

    {{-- Edit Modal --}}
    <div x-cloak x-show="editingRecord" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4" @keydown.escape.window="editingRecord = null">
        <template x-if="editingRecord">
        <div @click.away="editingRecord = null" class="w-full max-w-4xl border-2 border-black bg-white p-5 shadow-[8px_8px_0_0_#000] max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-4"><h2 class="font-[Helvetica] font-bold text-sm uppercase">Edit Dana Talangan</h2><button type="button" @click="editingRecord = null" class="text-lg font-bold">&times;</button></div>
            <form method="POST" :action="'/dana-talangan/' + editingRecord?.id" class="space-y-4">
                @csrf @method('PUT')
                <input type="hidden" name="form_mode" value="edit">
                <input type="hidden" name="record_id" :value="editingRecord.id">
                <input type="hidden" name="month" value="{{ $selectedMonth }}">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tanggal</label><div class="date-wrapper" data-accent="#f1c40f" style="position:relative"><div class="date-display w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between" tabindex="0"><span class="date-text">— Pilih Tanggal —</span><span class="date-arrow">▼</span></div><input type="date" name="tanggal" x-model="editingRecord.tanggal" required style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0"></div></div>
                    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Nama Konsumen</label><input name="nama_konsumen" x-model="editingRecord.nama_konsumen" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman']"></div>
                    @if(Auth::user()->canViewAllBranches())
                    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang</label><select name="branch_id" x-model="editingRecord.branch_id" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white">@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></div>
                    @endif
                    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek</label><select name="project_name" x-model="editingRecord.project_name" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white">@foreach($projects as $project)<option value="{{ $project->project_name }}">{{ $project->project_name }}</option>@endforeach</select></div>
                    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Kav</label><select name="kav" x-model="editingRecord.kav" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white"><option value="">— Pilih Kav —</option>@foreach($kavlings as $kavling)<option value="{{ $kavling->kavling_code }}">{{ $kavling->kavling_code }}</option>@endforeach</select></div>
                    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Marketing</label><input name="nama_marketing" x-model="editingRecord.nama_marketing" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman']"></div>
                    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Pekerjaan</label><input name="pekerjaan" x-model="editingRecord.pekerjaan" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman']"></div>
                    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Status Kawin</label><input name="status_perkawinan" x-model="editingRecord.status_perkawinan" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman']"></div>
                    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Umur</label><input type="number" name="umur" x-model="editingRecord.umur" min="0" max="150" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman']"></div>
                    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">TGL Komitmen</label><div class="date-wrapper" data-accent="#f1c40f" style="position:relative"><div class="date-display w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between" tabindex="0"><span class="date-text">— Pilih Tanggal —</span><span class="date-arrow">▼</span></div><input type="date" name="tgl_komitmen" x-model="editingRecord.tgl_komitmen" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0"></div></div>
                    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Status Cicilan</label><select name="status" x-model="editingRecord.status" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white"><option value="sanggup">Sanggup</option><option value="tidak_sanggup">Tidak Sanggup</option><option value="lunas">Lunas</option></select></div>
                    <div class="flex items-center gap-6 pt-5"><label class="flex items-center gap-2 text-sm font-['Times_New_Roman']"><input type="hidden" name="pinjam_nama" value="0"><input type="checkbox" name="pinjam_nama" value="1" x-model="editingRecord.pinjam_nama" class="w-5 h-5 accent-[#f1c40f]"> Pinjam Nama</label><label class="flex items-center gap-2 text-sm font-['Times_New_Roman']"><input type="hidden" name="konfirmasi_keuangan" value="0"><input type="checkbox" name="konfirmasi_keuangan" value="1" x-model="editingRecord.konfirmasi_keuangan" class="w-5 h-5 accent-[#f1c40f]"> Konfirmasi</label></div>
                    <div class="sm:col-span-2"><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Penyelesaian</label><textarea name="penyelesaian" x-model="editingRecord.penyelesaian" rows="3" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman']"></textarea></div>
                </div>
                @if($errors->any() && old('form_mode') === 'edit')
                <div class="border-2 border-black bg-[#d77a7a] px-3 py-2 text-sm font-['Times_New_Roman']">{{ $errors->first() }}</div>
                @endif
                <div class="flex gap-2"><button class="bg-black text-white border-2 border-black px-6 py-2 text-sm font-[Helvetica] font-bold">Simpan</button><button type="button" @click="editingRecord = null" class="bg-white border-2 border-black px-6 py-2 text-sm font-[Helvetica] font-bold">Batal</button></div>
            </form>
        </div>
        </template>
    </div>

    <x-crm.bulk-bar
        destroy-route="{{ route('dana-talangan.bulk-destroy') }}"
        update-route="{{ route('dana-talangan.bulk-update') }}"
        :status-options="['sanggup' => 'Sanggup', 'tidak_sanggup' => 'Tidak Sanggup', 'lunas' => 'Lunas']"
        status-label="Status Cicilan"
        accent-color="#f1c40f"
        :params="request()->only(['branch_id', 'project_name', 'status', 'month'])" />

<x-crm.detail-modal
    title-key="nama_konsumen"
    notes-key="penyelesaian"
    creator-key="creator.name"
    :fields="[
        ['key' => 'kav', 'label' => 'Kav'],
        ['key' => 'project_name', 'label' => 'Proyek'],
        ['key' => 'tanggal', 'label' => 'Tanggal', 'type' => 'date'],
        ['key' => 'pinjam_nama', 'label' => 'Pinjam Nama', 'type' => 'boolean'],
        ['key' => 'pekerjaan', 'label' => 'Pekerjaan'],
        ['key' => 'status_perkawinan', 'label' => 'Status Kawin'],
        ['key' => 'umur', 'label' => 'Umur'],
        ['key' => 'nama_marketing', 'label' => 'Marketing', 'colspan' => 2],
        ['key' => 'tgl_komitmen', 'label' => 'TGL Komitmen', 'type' => 'date'],
        ['key' => 'konfirmasi_keuangan', 'label' => 'Konfirmasi Keuangan', 'type' => 'boolean'],
        ['key' => 'status', 'label' => 'Status Cicilan', 'type' => 'badge'],
    ]"
    status-colors='{"sanggup":"#9ab6c8","tidak_sanggup":"#d77a7a","lunas":"#b3bd95"}'
/>
</div>
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
