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
    <p class="mb-4 text-sm">Unduh template resmi, isi data sesuai sheet REFERENSI, lalu unggah workbook XLSX berukuran maksimal 5 MB. CSV tidak didukung.</p>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('admin-users.import-template') }}" class="border-2 border-black bg-[#8c9ae0] px-4 py-2 text-xs font-bold">UNDUH TEMPLATE XLSX</a>
        <a href="{{ route('admin-users.import-history') }}" class="border-2 border-black bg-white px-4 py-2 text-xs font-bold">RIWAYAT IMPORT</a>
    </div>
    <form method="POST" action="{{ route('admin-users.import-preview') }}" enctype="multipart/form-data" class="mt-5 border-2 border-dashed border-black bg-gray-100 p-6">
        @csrf
        <label for="file" class="mb-2 block text-xs font-bold">FILE XLSX</label>
        <div class="flex flex-wrap items-center gap-2">
            <input id="file" name="file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required class="max-w-full border-2 border-black bg-white p-2 text-sm">
            <button class="border-2 border-black bg-[#8c9ae0] px-4 py-2 text-xs font-bold">UNGGAH DAN VALIDASI</button>
        </div>
        @error('file')<p class="mt-2 text-sm font-bold text-[#c0392b]">{{ $message }}</p>@enderror
        <label class="mt-4 flex items-start gap-2 text-sm">
            <input type="hidden" name="send_invitations" value="0">
            <input type="checkbox" name="send_invitations" value="1" @checked(old('send_invitations')) class="mt-0.5 h-4 w-4 border-2 border-black">
            <span><strong>Kirim undangan untuk semua akun saat import dikonfirmasi.</strong><br><span class="text-xs">Jika dipilih, baris pending_invitation ikut dikirim undangannya. Jika tidak, hanya baris berstatus invited yang dikirim.</span></span>
        </label>
    </form>
</div>
@endsection
