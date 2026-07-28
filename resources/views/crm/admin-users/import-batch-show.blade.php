@extends('layouts.crm')
@section('title', 'Detail Import Pengguna - Oasis CRM')
@section('content')
<x-crm.page-header color="#8c9ae0" title="Detail Import Pengguna" />
<div class="mb-4 grid grid-cols-2 gap-0 border-2 border-black bg-white text-center text-xs font-bold sm:grid-cols-5">
    @foreach(['1. UNGGAH', '2. VALIDASI', '3. TINJAU', '4. KONFIRMASI', '5. SELESAI'] as $step)
        <div class="border-r border-black px-2 py-3 {{ $loop->iteration <= 3 ? 'bg-[#8c9ae0]' : '' }}">{{ $step }}</div>
    @endforeach
</div>

<div class="mb-4 border-2 border-black bg-white p-4">
    <dl class="grid gap-3 text-sm sm:grid-cols-4"><div><dt class="font-bold">FILE</dt><dd>{{ $batch->original_filename }}</dd></div><div><dt class="font-bold">STATUS BATCH</dt><dd>{{ strtoupper(str_replace('_', ' ', $batch->status)) }}</dd></div><div><dt class="font-bold">PENGUNGGAH</dt><dd>{{ $batch->uploader?->name ?? '-' }}</dd></div><div><dt class="font-bold">KEDALUWARSA</dt><dd>{{ $batch->expires_at?->format('d/m/Y H:i') ?? '-' }}</dd></div></dl>
</div>

<div class="mb-4 grid grid-cols-2 border-2 border-black bg-white sm:grid-cols-4">
    @foreach([['TOTAL', $batch->total_rows, '#ffffff'], ['VALID', $batch->valid_rows, '#d8f3dc'], ['PERINGATAN', $batch->warning_rows, '#fff3b0'], ['ERROR', $batch->error_rows, '#ffd6d6']] as [$label, $count, $color])
        <div class="border-r border-black p-4 text-center" style="background-color: {{ $color }}"><div class="text-2xl font-bold">{{ $count }}</div><div class="text-xs font-bold">{{ $label }}</div></div>
    @endforeach
</div>

@if($batch->error_rows > 0)
    <div class="mb-4 border-2 border-black bg-[#ffd6d6] p-3 text-sm"><strong>Konfirmasi dinonaktifkan.</strong> Perbaiki semua baris berstatus error dan unggah file yang sudah dikoreksi.</div>
@elseif($batch->warning_rows > 0)
    <div class="mb-4 border-2 border-black bg-[#fff3b0] p-3 text-sm">Tidak ada error. Tinjau seluruh peringatan sebelum melanjutkan pada tahap konfirmasi.</div>
@else
    <div class="mb-4 border-2 border-black bg-[#d8f3dc] p-3 text-sm">Seluruh baris lolos validasi dan siap untuk tahap konfirmasi.</div>
@endif

<div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Baris Excel</th><th>Nama</th><th>Email</th><th>Role</th><th>Cabang Utama</th><th>Cabang Tambahan</th><th>Proyek Utama</th><th>Proyek Tambahan</th><th>Atasan Langsung</th><th>Status Akun</th><th>Hasil Validasi</th><th>Pesan</th></tr></thead>
<tbody>@forelse($batch->rows as $row)
    @php($raw = $row->raw_data)
    <tr>
        <td>{{ $row->row_number }}</td><td title="{{ $raw['name'] ?? '' }}">{{ $raw['name'] ?? '-' }}</td><td>{{ $raw['email'] ?? '-' }}</td><td>{{ $raw['role'] ?? '-' }}</td><td>{{ $raw['primary_branch'] ?? '-' }}</td><td title="{{ $raw['additional_branches'] ?? '' }}">{{ ($raw['additional_branches'] ?? '') ?: '-' }}</td><td>{{ ($raw['primary_project'] ?? '') ?: '-' }}</td><td title="{{ $raw['additional_projects'] ?? '' }}">{{ ($raw['additional_projects'] ?? '') ?: '-' }}</td><td>{{ ($raw['supervisor_email'] ?? '') ?: '-' }}</td><td>{{ ($raw['status'] ?? '') ?: 'pending_invitation' }}</td>
        <td><span class="inline-block border border-black px-2 py-1 text-xs font-bold {{ $row->validation_status === 'error' ? 'bg-[#ffd6d6]' : ($row->validation_status === 'warning' ? 'bg-[#fff3b0]' : 'bg-[#d8f3dc]') }}">{{ strtoupper($row->validation_status) }}</span></td>
        <td class="min-w-72">@foreach($row->errors ?? [] as $message)<div class="font-bold text-[#c0392b]">{{ $message }}</div>@endforeach @foreach($row->warnings ?? [] as $message)<div class="font-bold text-[#8a6500]">{{ $message }}</div>@endforeach @if(empty($row->errors) && empty($row->warnings))<span>Lolos validasi.</span>@endif</td>
    </tr>
@empty<tr><td colspan="12" class="text-center">Tidak ada baris data pengguna untuk ditinjau.</td></tr>@endforelse</tbody></table></div>

<div class="mt-4 flex flex-wrap items-center gap-2 border-2 border-black bg-white p-3">
    <a href="{{ route('admin-users.import') }}" class="border-2 border-black bg-white px-4 py-2 text-xs font-bold">UNGGAH FILE YANG DIKOREKSI</a>
    <a href="{{ route('admin-users.import-history') }}" class="border-2 border-black bg-white px-4 py-2 text-xs font-bold">KEMBALI KE RIWAYAT</a>
    <form action="#" method="POST" onsubmit="return false" class="sm:ml-auto">@csrf
        <button disabled class="border-2 border-black bg-gray-300 px-4 py-2 text-xs font-bold opacity-60">{{ $batch->error_rows > 0 ? 'PERBAIKI ERROR SEBELUM KONFIRMASI' : 'KONFIRMASI TERSEDIA PADA TAHAP BERIKUTNYA' }}</button>
    </form>
</div>
@endsection
