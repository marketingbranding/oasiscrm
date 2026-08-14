@extends('layouts.crm')
@section('title', 'Impor Promo TSV - Oasis CRM')
@section('content')
<x-crm.page-header variant="canonical" eyebrow="Administrasi Sales" title="Impor Promo TSV" description="Tempel maksimal 500 baris TSV. Preview tidak mengubah data promo." />
<form method="POST" action="{{ route('promos.import.preview') }}" class="grid max-w-4xl gap-4 border-2 border-black bg-white p-4">@csrf
    <x-crm.field label="Cabang" for="branch_id" required><select id="branch_id" name="branch_id" class="crm-control" required @disabled($branchLocked)><option value="">Pilih cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id', $selectedBranchId) == $branch->id)>{{ $branch->name }}</option>@endforeach</select>@if($branchLocked)<input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">@endif<x-crm.input-error :messages="$errors->get('branch_id')" /></x-crm.field>
    <x-crm.field label="Data TSV" for="tsv" required><textarea id="tsv" name="tsv" rows="16" class="crm-control font-mono" maxlength="262144" required placeholder="id_promo&#9;nama_promo&#9;tanggal_mulai&#9;tanggal_selesai&#9;keterangan">{{ old('tsv') }}</textarea><x-crm.input-error :messages="$errors->get('tsv')" /></x-crm.field>
    <p class="text-sm">Urutan kolom: id_promo, nama_promo, tanggal_mulai, tanggal_selesai, keterangan. Tanggal: d/m/Y atau Y-m-d.</p>
    <div class="flex gap-2"><x-crm.button type="submit" variant="primary" accent="sales">Buat Preview</x-crm.button><x-crm.button href="{{ route('promos.index') }}" variant="secondary">Batal</x-crm.button></div>
</form>
@endsection
