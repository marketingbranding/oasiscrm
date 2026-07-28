@extends('layouts.crm')
@section('title', 'Import Pengguna - Oasis CRM')
@section('content')
<x-crm.page-header color="#8c9ae0" title="Import Pengguna XLSX" />

<div class="mb-4 grid grid-cols-2 gap-0 border-2 border-black bg-white text-center text-xs font-bold sm:grid-cols-5">
    @foreach(['1. UNGGAH', '2. VALIDASI', '3. TINJAU', '4. KONFIRMASI', '5. SELESAI'] as $step)
        <div class="border-r border-black px-2 py-3 {{ $loop->first ? 'bg-[#8c9ae0]' : '' }}">{{ $step }}</div>
    @endforeach
</div>

<div class="border-2 border-black bg-white p-5">
    <h2 class="mb-2 font-[Helvetica] text-lg font-bold">SIAPKAN DATA PENGGUNA</h2>
    <p class="mb-4 text-sm">Unduh template resmi, isi data sesuai sheet REFERENSI, lalu simpan dalam format XLSX. Fitur unggah dan validasi akan tersedia pada tahap berikutnya.</p>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('admin-users.import-template') }}" class="border-2 border-black bg-[#8c9ae0] px-4 py-2 text-xs font-bold">UNDUH TEMPLATE XLSX</a>
        <a href="{{ route('admin-users.import-history') }}" class="border-2 border-black bg-white px-4 py-2 text-xs font-bold">RIWAYAT IMPORT</a>
    </div>
    <div class="mt-5 border-2 border-dashed border-black bg-gray-100 p-6 text-center">
        <label class="mb-2 block text-xs font-bold">FILE XLSX</label>
        <input type="file" accept=".xlsx" disabled class="max-w-full border-2 border-black bg-gray-200 p-2 opacity-60">
        <button type="button" disabled class="ml-2 border-2 border-black bg-gray-300 px-4 py-2 text-xs font-bold opacity-60">UNGGAH DAN VALIDASI</button>
        <p class="mt-2 text-xs">Unggah belum diaktifkan pada tahap ini.</p>
    </div>
</div>
@endsection
