@extends('layouts.crm')

@section('title', 'Tambah Konsumen - Oasis CRM')

@section('content')
<x-crm.page-header variant="canonical" title="Tambah Konsumen" eyebrow="Data Konsumen" description="Input manual data konsumen sesuai Database Master 2026." />
<form method="POST" action="{{ route('consumer-local.store') }}" class="space-y-4">
@csrf
<div class="grid gap-4 bg-white p-4 md:grid-cols-2">
@foreach(['project_id' => ['Proyek', $projects], 'kavling_id' => ['Kavling', $kavlings], 'sales_user_id' => ['Sales', $sales]] as $name => [$label, $options])
<label class="text-xs font-bold uppercase">{{ $label }}<select name="{{ $name }}" class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2"><option value="">Pilih {{ $label }}</option>@foreach($options as $option)<option value="{{ $option->id }}" @selected(old($name) == $option->id)>{{ $option->project_name ?? $option->kavling_code ?? $option->name }}</option>@endforeach</select>@error($name)<span class="text-red-700">{{ $message }}</span>@enderror</label>
@endforeach
@foreach(['nik' => 'NIK', 'nama_konsumen' => 'Nama Konsumen', 'tanggal_lahir' => 'Tanggal Lahir', 'pekerjaan' => 'Pekerjaan', 'detail_pekerjaan' => 'Detail Pekerjaan', 'no_hp' => 'No HP', 'nama_kondar' => 'Nama Kontak Darurat', 'no_hp_kondar' => 'No HP Kontak Darurat', 'kelurahan' => 'Kelurahan', 'kecamatan' => 'Kecamatan', 'kabupaten_kota' => 'Kabupaten/Kota'] as $name => $label)
<label class="text-xs font-bold uppercase">{{ $label }}<input name="{{ $name }}" type="{{ $name === 'tanggal_lahir' ? 'date' : 'text' }}" value="{{ old($name) }}" class="mt-1 block w-full border border-gray-300 px-3 py-2">@error($name)<span class="text-red-700">{{ $message }}</span>@enderror</label>
@endforeach
<label class="text-xs font-bold uppercase md:col-span-2">Alamat<textarea name="alamat" class="mt-1 block w-full border border-gray-300 px-3 py-2">{{ old('alamat') }}</textarea></label>
<label class="text-xs font-bold uppercase">Status Konsumen<select name="consumer_status" class="mt-1 block w-full border border-gray-300 px-3 py-2">@foreach(['Lanjut','Reject'] as $status)<option value="{{ $status }}" @selected(old('consumer_status', 'Lanjut') === $status)>{{ $status }}</option>@endforeach</select></label>
<label class="text-xs font-bold uppercase">Status Cash<select name="status_cash" class="mt-1 block w-full border border-gray-300 px-3 py-2"><option value="">Belum diisi</option><option value="1" @selected(old('status_cash') === '1')>Ya</option><option value="0" @selected(old('status_cash') === '0')>Tidak</option></select></label>
<label class="text-xs font-bold uppercase md:col-span-2">Keterangan<textarea name="notes" class="mt-1 block w-full border border-gray-300 px-3 py-2">{{ old('notes') }}</textarea></label>
</div>
<div class="flex gap-2"><button class="border-2 border-black bg-[#fcc20f] px-4 py-2 font-bold">Simpan</button><a href="{{ route('consumer-local.index') }}" class="border border-gray-400 bg-white px-4 py-2 font-bold">Batal</a></div>
</form>
@endsection
