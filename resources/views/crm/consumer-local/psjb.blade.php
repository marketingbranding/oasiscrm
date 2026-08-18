@extends('layouts.crm')
@section('title', 'Input PSJB - Oasis CRM')
@section('content')
<x-crm.page-header variant="canonical" title="Input PSJB" eyebrow="Proses Penjualan" description="Catat PSJB terstruktur. ID PSJB dan ID Konsumen dibuat sistem." />
<div class="mb-4 border border-gray-300 bg-white p-4 text-sm"><strong>{{ $application->customer?->name }}</strong> · Kavling: {{ $application->kavling?->kavling_code ?? 'Belum Ada Data' }} · BI Checking: {{ $application->stageEvents()->where('stage', 'bi_checking')->latest('occurred_at')->first()?->source_id ?? 'Belum Ada Data' }}</div>
<form method="POST" action="{{ route('consumer-local.psjb.store', $application) }}" class="grid gap-4 bg-white p-4 md:grid-cols-2">@csrf
@foreach(['tanggal_psjb'=>'Tanggal PSJB','harga_unit'=>'Harga Unit','tanggal_utj'=>'Tanggal UTJ','utj'=>'UTJ','tanggal_dp_klt'=>'Tanggal DP KLT','dp_all_in'=>'DP All In','nominal_cicilan'=>'Nominal Cicilan','jumlah_cicilan'=>'Jumlah Cicilan','luas_klt'=>'Luas KLT','harga_klt_m'=>'Harga KLT/m','harga_klt_total'=>'Harga KLT Total','cara_pembayaran'=>'Cara Pembayaran','status'=>'Status'] as $name => $label)
<label class="text-xs font-bold uppercase">{{ $label }}<input name="{{ $name }}" type="{{ str_starts_with($name, 'tanggal_') ? 'date' : 'text' }}" value="{{ old($name) }}" class="mt-1 block w-full border border-gray-300 px-3 py-2"></label>
@endforeach
<label class="text-xs font-bold uppercase md:col-span-2">Keterangan<textarea name="keterangan" class="mt-1 block w-full border border-gray-300 px-3 py-2">{{ old('keterangan') }}</textarea></label>
<button class="border-2 border-black bg-[#fcc20f] px-4 py-2 font-bold md:col-span-2">Simpan PSJB</button></form>
@endsection
