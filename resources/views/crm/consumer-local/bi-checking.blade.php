@extends('layouts.crm')
@section('title', 'Input BI Checking - Oasis CRM')
@section('content')
<x-crm.page-header variant="canonical" title="Input BI Checking" eyebrow="Proses Penjualan" description="Catat percobaan BI Checking baru tanpa menghapus riwayat sebelumnya." />
<div class="mb-4 border border-gray-300 bg-white p-4 text-sm"><strong>{{ $application->customer?->name }}</strong> · NIK: •••••••••••••••• · Kavling: {{ $application->kavling?->kavling_code ?? 'Belum Ada Data' }}</div>
<form method="POST" action="{{ route('consumer-local.bi-checking.store', $application) }}" class="max-w-2xl space-y-4 bg-white p-4">@csrf
<label class="block text-xs font-bold uppercase">Tanggal SLIK<input type="date" name="tanggal_slik" value="{{ old('tanggal_slik', today()->toDateString()) }}" class="mt-1 block w-full border border-gray-300 px-3 py-2">@error('tanggal_slik')<span class="text-red-700">{{ $message }}</span>@enderror</label>
<label class="block text-xs font-bold uppercase">Hasil SLIK<input name="hasil_slik" value="{{ old('hasil_slik') }}" placeholder="OK, KOL 1, NO BIC" class="mt-1 block w-full border border-gray-300 px-3 py-2">@error('hasil_slik')<span class="text-red-700">{{ $message }}</span>@enderror</label>
<label class="block text-xs font-bold uppercase">Keterangan<textarea name="keterangan" class="mt-1 block w-full border border-gray-300 px-3 py-2">{{ old('keterangan') }}</textarea></label>
<button class="border-2 border-black bg-[#fcc20f] px-4 py-2 font-bold">Simpan BI Checking</button></form>
@endsection
