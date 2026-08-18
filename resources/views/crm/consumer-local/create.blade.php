@extends('layouts.crm')

@section('title', 'Tambah Konsumen - Oasis CRM')

@section('content')
<x-crm.page-header variant="canonical" title="Tambah Konsumen" eyebrow="Proses Penjualan" description="Input manual data konsumen sesuai Database Master 2026." />
<form method="POST" action="{{ route('consumer-local.store') }}" class="space-y-4" x-data="{ projectId: @js(old('project_id', '')), kavlingId: @js(old('kavling_id', '')), kavlings: @js($kavlings->map(fn ($kavling) => ['id' => (string) $kavling->id, 'project_id' => (string) $kavling->project_id, 'code' => $kavling->kavling_code, 'name' => $kavling->name])->values()) }" x-init="$watch('projectId', () => { if (!kavlings.find(k => k.id === kavlingId && k.project_id === projectId)) kavlingId = '' })">
@csrf
<div class="grid gap-4 bg-white p-4 md:grid-cols-2">
<label class="text-xs font-bold uppercase">Proyek<select name="project_id" x-model="projectId" class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2" required><option value="">Pilih Proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->project_name }}</option>@endforeach</select>@error('project_id')<span class="text-red-700">{{ $message }}</span>@enderror</label>
<label class="text-xs font-bold uppercase">Kavling<select name="kavling_id" x-model="kavlingId" class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2"><option value="">Tanpa Kavling</option><template x-for="kavling in kavlings.filter(item => item.project_id === projectId)" :key="kavling.id"><option :value="kavling.id" x-text="kavling.code + (kavling.name && kavling.name !== kavling.code ? ' — ' + kavling.name : '')"></option></template></select>@error('kavling_id')<span class="text-red-700">{{ $message }}</span>@enderror</label>
<label class="text-xs font-bold uppercase">Sales<select name="sales_user_id" class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2"><option value="">Belum diisi</option>@foreach($sales as $option)<option value="{{ $option->id }}" @selected(old('sales_user_id') == $option->id)>{{ $option->name }}</option>@endforeach</select></label>
@foreach(['nik' => 'NIK', 'nama_konsumen' => 'Nama Konsumen', 'tanggal_lahir' => 'Tanggal Lahir', 'pekerjaan' => 'Pekerjaan', 'detail_pekerjaan' => 'Detail Pekerjaan', 'no_hp' => 'No HP', 'nama_kondar' => 'Nama Kontak Darurat', 'no_hp_kondar' => 'No HP Kontak Darurat', 'kelurahan' => 'Kelurahan', 'kecamatan' => 'Kecamatan', 'kabupaten_kota' => 'Kabupaten/Kota'] as $name => $label)
<label class="text-xs font-bold uppercase">{{ $label }}<input name="{{ $name }}" type="{{ $name === 'tanggal_lahir' ? 'date' : 'text' }}" value="{{ old($name) }}" class="mt-1 block w-full border border-gray-300 px-3 py-2">@error($name)<span class="text-red-700">{{ $message }}</span>@enderror</label>
@endforeach
<label class="text-xs font-bold uppercase md:col-span-2">Alamat<textarea name="alamat" class="mt-1 block w-full border border-gray-300 px-3 py-2">{{ old('alamat') }}</textarea></label>
<label class="text-xs font-bold uppercase">Status Konsumen<select name="consumer_status" class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2">@foreach(['Lanjut','Reject'] as $status)<option value="{{ $status }}" @selected(old('consumer_status', 'Lanjut') === $status)>{{ $status }}</option>@endforeach</select></label>
<label class="text-xs font-bold uppercase">Status Cash<select name="status_cash" class="mt-1 block w-full border border-gray-300 bg-white px-3 py-2"><option value="">Belum diisi</option><option value="1" @selected(old('status_cash') === '1')>Ya</option><option value="0" @selected(old('status_cash') === '0')>Tidak</option></select></label>
<label class="text-xs font-bold uppercase md:col-span-2">Keterangan<textarea name="notes" class="mt-1 block w-full border border-gray-300 px-3 py-2">{{ old('notes') }}</textarea></label>
</div>
<div class="flex gap-2"><button class="border-2 border-black bg-[#fcc20f] px-4 py-2 font-bold">Simpan</button><a href="{{ route('consumer-local.index') }}" class="border border-gray-400 bg-white px-4 py-2 font-bold">Batal</a></div>
</form>
@endsection
