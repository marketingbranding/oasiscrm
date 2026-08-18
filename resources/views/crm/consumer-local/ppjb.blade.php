@extends('layouts.crm')
@section('title', 'PPJB Developer - Oasis CRM')
@section('content')
<x-crm.page-header variant="canonical" title="PPJB Developer" eyebrow="Proses Konsumen" description="Catat tanggal proses PPJB tanpa mengisi ID teknis." />
<form method="POST" action="{{ route('consumer-local.ppjb.store', $application) }}" class="max-w-2xl space-y-4 bg-white p-4">@csrf<label class="block text-xs font-bold uppercase">Tanggal SP3K<input type="date" name="tanggal_sp3k" class="mt-1 block w-full border border-gray-300 px-3 py-2"></label><label class="block text-xs font-bold uppercase">Tanggal TTD PPJB<input type="date" name="tanggal_ttd_ppjb" value="{{ old('tanggal_ttd_ppjb') }}" required class="mt-1 block w-full border border-gray-300 px-3 py-2"></label><label class="block text-xs font-bold uppercase">Keterangan<textarea name="notes" class="mt-1 block w-full border border-gray-300 px-3 py-2"></textarea></label><button class="border-2 border-black bg-[#fcc20f] px-4 py-2 font-bold">Simpan PPJB</button></form>
@endsection
