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
    filterMode: @js($filterMode),
    tableFrozen: true,
    openEdit(record) { this.editingRecord = record; },
}">
    <x-crm.page-header color="#f1c40f" title="Dana Talangan" />

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

    @if($syncStatus?->message)
    <div class="border-2 border-black bg-[#fef3cd] px-4 py-3 mb-4 text-sm font-['Times_New_Roman'] whitespace-pre-line">{{ $syncStatus->message }}</div>
    @endif

    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('dana-talangan.index') }}" class="space-y-3 filter-bar">
            <div class="flex items-end gap-3 flex-wrap">
            @if(Auth::user()->canViewAllBranches() && isset($branches) && $branches->count() > 0)
            <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang</label>
            <select name="branch_id" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Cabang —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </select></div>
            @endif

            @if(isset($projects) && $projects->count() > 0)
            <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek</label>
            <select name="project_name" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Proyek —</option>
                @foreach($projectOptions as $projectName)
                    <option value="{{ $projectName }}" {{ $selectedProject === $projectName ? 'selected' : '' }}>{{ $projectName }}</option>
                @endforeach
            </select></div>
            @endif

            <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Status Cicilan</label>
            <select name="status" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Status —</option>
                <option value="sanggup" {{ $selectedStatus === 'sanggup' ? 'selected' : '' }}>Sanggup</option>
                <option value="tidak_sanggup" {{ $selectedStatus === 'tidak_sanggup' ? 'selected' : '' }}>Tidak Sanggup</option>
                <option value="lunas" {{ $selectedStatus === 'lunas' ? 'selected' : '' }}>Lunas</option>
            </select></div>
            <div class="grow min-w-[220px]"><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cari Nama Konsumen</label>
                <input name="search" value="{{ $search }}" placeholder="Ketik nama konsumen..." class="w-full border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
            </div>
            <div class="flex items-center gap-2 ml-auto pb-0.5">
                <x-crm.export-import export-route="dana-talangan.export" import-route="dana-talangan.import" :params="request()->only(['branch_id', 'project_name', 'status', 'search', 'filter_mode', 'date_from', 'date_to', 'month_from', 'month_to'])" />
                <button type="button" @click="adding = true" class="bg-[#f1c40f] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#d4ac0d]">
                    + Dana Talangan Baru
                </button>
            </div>
            </div>

            <div class="border-t-2 border-black pt-3 flex items-end gap-3 flex-wrap">
                <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Mode Rentang</label>
                    <select name="filter_mode" x-model="filterMode" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white">
                        <option value="date">Rentang Tanggal</option><option value="month">Rentang Bulan</option>
                    </select>
                </div>
                <template x-if="filterMode === 'date'">
                    <div class="flex items-end gap-3 flex-wrap">
                        <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Dari Tanggal</label><div class="date-wrapper" data-accent="#f1c40f" style="position:relative"><div class="date-display min-w-[170px] border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white cursor-pointer flex justify-between" tabindex="0"><span class="date-text">— Pilih Tanggal —</span><span class="date-arrow">▼</span></div><input type="date" name="date_from" value="{{ $dateFrom }}" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0"></div></div>
                        <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Sampai Tanggal</label><div class="date-wrapper" data-accent="#f1c40f" style="position:relative"><div class="date-display min-w-[170px] border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white cursor-pointer flex justify-between" tabindex="0"><span class="date-text">— Pilih Tanggal —</span><span class="date-arrow">▼</span></div><input type="date" name="date_to" value="{{ $dateTo }}" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0"></div></div>
                    </div>
                </template>
                <template x-if="filterMode === 'month'">
                    <div class="flex items-end gap-3 flex-wrap">
                        <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Dari Bulan</label><input type="month" name="month_from" value="{{ $monthFrom }}" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white"></div>
                        <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Sampai Bulan</label><input type="month" name="month_to" value="{{ $monthTo }}" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white"></div>
                    </div>
                </template>
                <button class="bg-black text-white border-2 border-black px-5 py-1.5 text-sm font-[Helvetica] font-bold">Terapkan</button>
                <a href="{{ route('dana-talangan.index') }}" class="bg-white border-2 border-black px-5 py-1.5 text-sm font-[Helvetica] font-bold hover:bg-gray-100">Reset</a>
            </div>
        </form>
    </div>

    @if($search !== '')
    <div class="border-2 border-black bg-[#fef3cd] p-4 mb-4">
        <div class="font-[Helvetica] font-bold text-xs uppercase mb-2">Riwayat Pengajuan Konsumen</div>
        @forelse($trackingSummary as $tracking)
        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-black py-2 first:border-t-0">
            <strong class="font-['Times_New_Roman']">{{ $tracking['name'] }}</strong>
            <span class="font-['Times_New_Roman'] text-sm"><b>{{ $tracking['total'] }} kali</b> diajukan · {{ $tracking['within_range'] }} dalam rentang aktif</span>
        </div>
        @empty
        <p class="font-['Times_New_Roman'] text-sm italic">Nama konsumen tidak ditemukan.</p>
        @endforelse
    </div>
    @endif

    <div class="crm-table-scroll">
        <table class="crm-data-table dana-table" :class="{ frozen: tableFrozen }">
            <thead>
                <tr>
                    <th class="crm-select-col"><input type="checkbox" id="select-all" class="cursor-pointer"></th>
                    <th class="crm-row-num">No</th>
                    <th class="name-col" style="{{ $sortField === 'nama_konsumen' ? 'background:#5b7db9;' : '' }}">
                        <div class="flex items-center justify-between gap-2">
                            <a href="{{ route('dana-talangan.index', array_merge(request()->query(), ['sort' => 'nama_konsumen', 'dir' => $sortField === 'nama_konsumen' && $sortDir === 'asc' ? 'desc' : 'asc', 'page' => null])) }}">
                                Nama Konsumen{{ $sortField === 'nama_konsumen' ? ($sortDir === 'asc' ? ' ▼' : ' ▲') : '' }}
                            </a>
                            <button type="button" @click="tableFrozen = !tableFrozen" class="text-[10px] font-normal" :title="tableFrozen ? 'Lepas kolom frozen' : 'Bekukan kolom'" x-text="tableFrozen ? '🔒' : '🔓'"></button>
                        </div>
                    </th>
                    <x-crm.click-sort-th field="tanggal" route="dana-talangan.index" label="Tanggal" :currentSort="$sortField" :currentDir="$sortDir" />
                    <x-crm.click-sort-th field="kav" route="dana-talangan.index" label="Kav" :currentSort="$sortField" :currentDir="$sortDir" />
                    <x-crm.click-sort-th field="project_name" route="dana-talangan.index" label="Proyek" :currentSort="$sortField" :currentDir="$sortDir" />
                    <th>Pinjam Nama</th>
                    <x-crm.click-sort-th field="pekerjaan" route="dana-talangan.index" label="Pekerjaan" :currentSort="$sortField" :currentDir="$sortDir" />
                    <x-crm.click-sort-th field="status_perkawinan" route="dana-talangan.index" label="Status Kawin" :currentSort="$sortField" :currentDir="$sortDir" />
                    <x-crm.click-sort-th field="umur" route="dana-talangan.index" label="Umur" :currentSort="$sortField" :currentDir="$sortDir" align="center" />
                    <x-crm.click-sort-th field="nama_marketing" route="dana-talangan.index" label="Marketing" :currentSort="$sortField" :currentDir="$sortDir" />
                    <x-crm.click-sort-th field="tgl_komitmen" route="dana-talangan.index" label="TGL Komitmen" :currentSort="$sortField" :currentDir="$sortDir" />
                    <th>Progress Penagihan</th>
                    <th>Konfirmasi</th>
                    <x-crm.click-sort-th field="status" route="dana-talangan.index" label="Status Cicilan" :currentSort="$sortField" :currentDir="$sortDir" align="center" />
                    <th class="crm-actions">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($records as $i => $r)
                <tr>
                    <td class="crm-select-col"><input type="checkbox" class="row-checkbox cursor-pointer" value="{{ $r->id }}"></td>
                    <td class="crm-row-num">{{ method_exists($records, 'firstItem') ? $records->firstItem() + $i : $i + 1 }}</td>
                    <td class="name-col font-bold cursor-pointer hover:underline" title="{{ $r->nama_konsumen }}" @click.prevent="openDetail({{ $r->id }})">{{ $r->nama_konsumen }}</td>
                    <td title="{{ $r->tanggal->format('d M Y') }}">{{ $r->tanggal->format('d M Y') }}</td>
                    <td title="{{ $r->kav ?? '' }}">{{ $r->kav ?? '—' }}</td>
                    <td title="{{ $r->project_name ?? '' }}">{{ $r->project_name ?? '—' }}</td>
                    <td class="text-center"><span class="crm-boolean-box {{ $r->pinjam_nama ? 'is-checked' : '' }}">{{ $r->pinjam_nama ? '✓' : '' }}</span></td>
                    <td title="{{ $r->pekerjaan ?? '' }}">{{ $r->pekerjaan ?? '—' }}</td>
                    <td title="{{ $r->status_perkawinan ?? '' }}">{{ $r->status_perkawinan ?? '—' }}</td>
                    <td class="text-center">{{ $r->umur ?? '—' }}</td>
                    <td title="{{ $r->nama_marketing ?? '' }}">{{ $r->nama_marketing ?? '—' }}</td>
                    <td>{{ $r->tgl_komitmen ? \Carbon\Carbon::parse($r->tgl_komitmen)->format('d M Y') : '—' }}</td>
                    <td title="{{ $r->penyelesaian }}">{{ $r->penyelesaian ?? '—' }}</td>
                    <td class="text-center">
                        <span class="crm-boolean-box {{ $r->konfirmasi_keuangan ? 'is-checked' : '' }}">{{ $r->konfirmasi_keuangan ? '✓' : '' }}</span>
                    </td>
                    <td class="text-center">
                        <span class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black {{ $r->status === 'lunas' ? 'bg-white' : ($r->status === 'tidak_sanggup' ? 'bg-[#d77a7a] text-white' : 'bg-[#9ab6c8]') }}">
                            {{ $r->status === 'sanggup' ? 'SANGGUP' : ($r->status === 'tidak_sanggup' ? 'TIDAK SANGGUP' : 'LUNAS') }}
                        </span>
                    </td>
                    <td class="crm-actions">
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
                <h2 class="font-[Helvetica] font-bold text-sm uppercase">Tambah Dana Talangan</h2>
                <button type="button" @click="adding = false" class="text-lg font-bold">&times;</button>
            </div>
            <form method="POST" action="{{ route('dana-talangan.store') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="form_mode" value="add">
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
                            @foreach($projectOptions as $projectName)
                            <option value="{{ $projectName }}" {{ old('project_name', $selectedProject) === $projectName ? 'selected' : '' }}>{{ $projectName }}</option>
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
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tanggal</label><div class="date-wrapper" data-accent="#f1c40f" style="position:relative"><div class="date-display w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between" tabindex="0"><span class="date-text">— Pilih Tanggal —</span><span class="date-arrow">▼</span></div><input type="date" name="tanggal" x-model="editingRecord.tanggal" required style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0"></div></div>
                    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Nama Konsumen</label><input name="nama_konsumen" x-model="editingRecord.nama_konsumen" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman']"></div>
                    @if(Auth::user()->canViewAllBranches())
                    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang</label><select name="branch_id" x-model="editingRecord.branch_id" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white">@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></div>
                    @endif
                    <div><label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek</label><select name="project_name" x-model="editingRecord.project_name" required class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white">@foreach($projectOptions as $projectName)<option value="{{ $projectName }}">{{ $projectName }}</option>@endforeach</select></div>
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
        :params="request()->only(['branch_id', 'project_name', 'status', 'search', 'filter_mode', 'date_from', 'date_to', 'month_from', 'month_to'])" />

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
<style>
.dana-table th.name-col,
.dana-table td.name-col {
    min-width: 220px;
    max-width: 220px;
}
.dana-table.frozen th.crm-select-col,
.dana-table.frozen td.crm-select-col {
    position: sticky;
    left: 0;
}
.dana-table.frozen th.crm-row-num,
.dana-table.frozen td.crm-row-num {
    position: sticky;
    left: 44px;
}
.dana-table.frozen th.name-col,
.dana-table.frozen td.name-col {
    position: sticky;
    left: 88px;
    box-shadow: 3px 0 0 #000;
}
.dana-table.frozen thead th.crm-select-col { z-index: 15; }
.dana-table.frozen thead th.crm-row-num { z-index: 14; }
.dana-table.frozen thead th.name-col { z-index: 13; }
.dana-table.frozen tbody td.crm-select-col { z-index: 12; background: #fff; }
.dana-table.frozen tbody td.crm-row-num { z-index: 11; background: #fff; }
.dana-table.frozen tbody td.name-col { z-index: 10; background: #fff; }
.dana-table.frozen tbody tr:nth-child(even) td.crm-select-col,
.dana-table.frozen tbody tr:nth-child(even) td.crm-row-num,
.dana-table.frozen tbody tr:nth-child(even) td.name-col { background: #f9fafb; }
.dana-table.frozen tbody tr:hover td.crm-select-col,
.dana-table.frozen tbody tr:hover td.crm-row-num,
.dana-table.frozen tbody tr:hover td.name-col { background: #fef3c7; }
</style>
@endsection
