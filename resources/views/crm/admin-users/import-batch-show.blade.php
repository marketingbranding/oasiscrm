@extends('layouts.crm')
@section('title', 'Detail Import Pengguna - Oasis CRM')
@section('content')
<x-crm.page-header color="#8c9ae0" title="Detail Import Pengguna" />
<div class="border-2 border-black bg-white p-5">
    <dl class="grid gap-3 text-sm sm:grid-cols-2"><div><dt class="font-bold">FILE</dt><dd>{{ $batch->original_filename }}</dd></div><div><dt class="font-bold">STATUS</dt><dd>{{ strtoupper($batch->status) }}</dd></div><div><dt class="font-bold">PENGUNGGAH</dt><dd>{{ $batch->uploader?->name ?? '-' }}</dd></div><div><dt class="font-bold">DIBUAT</dt><dd>{{ $batch->created_at->format('d/m/Y H:i') }}</dd></div></dl>
    <p class="mt-5 border-2 border-black bg-[#fff3b0] p-3 text-sm">Rincian baris dan proses konfirmasi akan tersedia pada tahap berikutnya.</p>
    <a href="{{ route('admin-users.import-history') }}" class="mt-4 inline-block font-bold text-[#0000ee] underline">Kembali ke riwayat</a>
</div>
@endsection
