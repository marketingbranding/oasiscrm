@extends('layouts.crm')

@section('title', 'Laporan & Masukan - Oasis CRM')

@section('content')
    <x-crm.page-header color="#c0392b" title="Laporan & Masukan" />

    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('feedback-reports.index') }}" class="flex items-center gap-3 flex-wrap filter-bar">
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

            <label class="font-[Helvetica] font-bold text-xs uppercase">Tipe:</label>
            <select name="type" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Tipe —</option>
                <option value="masukan" {{ $selectedType === 'masukan' ? 'selected' : '' }}>Masukan</option>
                <option value="bug" {{ $selectedType === 'bug' ? 'selected' : '' }}>Bug</option>
            </select>

            <label class="font-[Helvetica] font-bold text-xs uppercase">Status:</label>
            <select name="status" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Semua Status —</option>
                <option value="pending" {{ $selectedStatus === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="approved" {{ $selectedStatus === 'approved' ? 'selected' : '' }}>Disetujui</option>
                <option value="rejected" {{ $selectedStatus === 'rejected' ? 'selected' : '' }}>Ditolak</option>
                <option value="implemented" {{ $selectedStatus === 'implemented' ? 'selected' : '' }}>Implementasi</option>
                <option value="fixed" {{ $selectedStatus === 'fixed' ? 'selected' : '' }}>Fixed</option>
            </select>

            <label class="font-[Helvetica] font-bold text-xs uppercase">Cari:</label>
            <input name="search" value="{{ request('search') }}"
                   placeholder="Judul atau deskripsi..."
                   class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none"
                   onkeydown="if(event.key==='Enter') this.form.submit()">
            </div>

            <div class="flex items-center gap-2 ml-auto">
                <a href="{{ route('feedback-reports.create') }}" class="bg-[#c0392b] text-white px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#a93226]">
                    + Laporan Baru
                </a>
            </div>
        </form>
    </div>

    <div class="border-2 border-black bg-white overflow-auto max-h-[calc(100vh-12rem)]">
        <table class="min-w-full text-sm font-['Times_New_Roman']">
            <thead>
                <tr class="bg-black text-white">
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase w-12">No</th>
                    <x-crm.sortable-th field="created_at" route="feedback-reports.index" label="Tanggal" :currentSort="$sortField" :currentDir="$sortDir" />
                    <x-crm.sortable-th field="type" route="feedback-reports.index" label="Tipe" :currentSort="$sortField" :currentDir="$sortDir" />
                    <x-crm.sortable-th field="title" route="feedback-reports.index" label="Judul" :currentSort="$sortField" :currentDir="$sortDir" classes="hidden lg:table-cell" />
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase hidden lg:table-cell">Cabang</th>
                    <th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase hidden lg:table-cell">Pelapor</th>
                    <x-crm.sortable-th field="status" route="feedback-reports.index" label="Status" :currentSort="$sortField" :currentDir="$sortDir" align="center" />
                    <th class="px-3 py-2 text-center font-[Helvetica] font-bold text-xs uppercase">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-black">
                @forelse($records as $i => $r)
                <tr class="{{ $r->user_id === Auth::id() ? 'bg-yellow-50' : '' }} hover:bg-gray-100">
                    <td class="px-3 py-2">{{ $records->firstItem() + $i ?? $i + 1 }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ $r->created_at->format('d M Y H:i') }}</td>
                    <td class="px-3 py-2">
                        <span class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black {{ $r->type === 'bug' ? 'bg-[#d77a7a] text-white' : 'bg-[#e6915d] text-white' }}">
                            {{ $r->type === 'bug' ? 'BUG' : 'MASUKAN' }}
                        </span>
                    </td>
                    <td class="px-3 py-2 font-bold max-w-[200px] truncate hidden lg:table-cell" title="{{ $r->title }}">{{ $r->title }}</td>
                    <td class="px-3 py-2 hidden lg:table-cell">{{ $r->branch->name ?? '—' }}</td>
                    <td class="px-3 py-2 hidden lg:table-cell">{{ $r->creator->name ?? '—' }}</td>
                    <td class="px-3 py-2 text-center">
                        @php
                            $statusMap = [
                                'pending' => ['bg-[#f1c40f]', 'PENDING'],
                                'approved' => ['bg-[#27ae60] text-white', 'DISETUJUI'],
                                'rejected' => ['bg-[#c0392b] text-white', 'DITOLAK'],
                                'implemented' => ['bg-[#2980b9] text-white', 'IMPLEMENTASI'],
                                'fixed' => ['bg-[#7f8c8d] text-white', 'FIXED'],
                            ];
                            [$statusClass, $statusLabel] = $statusMap[$r->status] ?? ['bg-gray-300', strtoupper($r->status)];
                        @endphp
                        <span class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black {{ $statusClass }}">
                            {{ $statusLabel }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-center">
                        <a href="{{ route('feedback-reports.edit', $r->id) }}" class="text-xs font-[Helvetica] font-bold underline hover:text-[#c0392b]">Detail</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-sm">Belum ada laporan.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if(isset($records) && $records instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <x-crm.pagination :collection="$records" :per-page="$perPage" />
    @endif
@endsection
