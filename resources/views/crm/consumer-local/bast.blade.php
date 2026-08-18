@extends('layouts.crm')
@section('title', 'BAST - Oasis CRM')
@section('content')
<x-crm.page-header variant="canonical" title="BAST" eyebrow="Proses Konsumen" description="Catat tanggal BAST untuk perjalanan konsumen." />
<form method="POST" action="{{ route('consumer-local.bast.store', $application) }}" class="max-w-xl space-y-4 bg-white p-4">@csrf<label class="block text-xs font-bold uppercase">Tanggal BAST<input type="date" name="tanggal_bast" value="{{ old('tanggal_bast') }}" required class="mt-1 block w-full border border-gray-300 px-3 py-2"></label><button class="border-2 border-black bg-[#fcc20f] px-4 py-2 font-bold">Simpan BAST</button></form>
@endsection
