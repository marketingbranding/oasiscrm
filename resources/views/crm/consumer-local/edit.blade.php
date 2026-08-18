@extends('layouts.crm')

@section('title', 'Edit Konsumen - Oasis CRM')

@section('content')
<x-crm.page-header variant="canonical" title="Edit Konsumen" eyebrow="Data Konsumen" description="Perbarui status operasional, sales, promo, dan keterangan." />
<form method="POST" action="{{ route('consumer-local.update', $application) }}" class="space-y-4">
@csrf @method('PUT')
<div class="grid gap-4 bg-white p-4 md:grid-cols-2">
<label class="text-xs font-bold uppercase">Sales<select name="sales_user_id" class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2"><option value="">Belum diisi</option>@foreach($sales as $option)<option value="{{ $option->id }}" @selected(old('sales_user_id', $application->sales_user_id) == $option->id)>{{ $option->name }}</option>@endforeach</select></label>
<label class="text-xs font-bold uppercase">Status Konsumen<select name="consumer_status" class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2">@foreach(['Lanjut','Mundur','Pindah Kavling','Reject'] as $status)<option value="{{ $status }}" @selected(old('consumer_status', $application->consumer_status) === $status)>{{ $status }}</option>@endforeach</select></label>
@if($application->consumer_status === 'Pindah Kavling')<label class="text-xs font-bold uppercase">Kavling Tujuan<select name="target_kavling_id" class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2"><option value="">Pilih Kavling</option>@foreach($kavlings as $kavling)<option value="{{ $kavling->id }}">{{ $kavling->kavling_code }}</option>@endforeach</select></label>@endif
<label class="text-xs font-bold uppercase">Status Cash<select name="status_cash" class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2"><option value="">Belum diisi</option><option value="1" @selected(old('status_cash', $application->status_cash) === true)>Ya</option><option value="0" @selected(old('status_cash', $application->status_cash) === false)>Tidak</option></select></label>
<label class="text-xs font-bold uppercase md:col-span-2">Keterangan<textarea name="notes" class="mt-1 block w-full border border-gray-300 px-3 py-2">{{ old('notes', $application->notes) }}</textarea></label>
</div>
<div class="flex gap-2"><button class="border-2 border-black bg-[#fcc20f] px-4 py-2 font-bold">Simpan</button><a href="{{ route('consumer-local.show', $application) }}" class="border border-gray-400 bg-white px-4 py-2 font-bold">Batal</a></div>
</form>
@endsection
