@extends('layouts.crm')
@section('title', 'Import Data Konsumen - Oasis CRM')
@section('content')
<x-crm.page-header variant="canonical" eyebrow="Konsumen Progress" title="Import Data Konsumen" description="Tempel data TSV untuk menyiapkan data konsumen lokal. Tidak mengubah data Google atau alur operasional." />
<form method="POST" action="{{ route('consumer-import.preview') }}" class="grid max-w-5xl gap-4 border-2 border-black bg-white p-4">
    @csrf
    <x-crm.field label="Cabang" for="branch_id" required>
        <select id="branch_id" name="branch_id" class="crm-control" required>
            <option value="">Pilih cabang</option>
            @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach
        </select>
        <x-crm.input-error :messages="$errors->get('branch_id')" />
    </x-crm.field>
    <x-crm.field label="Proyek" for="project_id" required>
        <select id="project_id" name="project_id" class="crm-control" required><option value="">Pilih cabang dahulu</option></select>
        <x-crm.input-error :messages="$errors->get('project_id')" />
    </x-crm.field>
    <x-crm.field label="Data TSV dari Spreadsheet" for="tsv" required>
        <textarea id="tsv" name="tsv" rows="18" class="crm-control font-mono" maxlength="262144" required placeholder="Nama Konsumen&#9;No HP&#9;Proyek&#9;Sales&#9;Kavling&#9;Promo&#9;Status&#9;Tahap&#9;Tanggal Booking&#9;Tanggal Akad&#9;Bank&#9;Status Bank&#9;External ID">{{ old('tsv') }}</textarea>
        <x-crm.input-error :messages="$errors->get('tsv')" />
    </x-crm.field>
    <p class="text-sm">Wajib: <strong>Nama Konsumen</strong>. Header lain opsional. Tanggal aman: YYYY-MM-DD atau M/D/YYYY (bulan/hari/tahun; contoh 08/12/2026 = 12 Agustus 2026). Format DD/MM/YYYY ambigu dan ditolak. Baris belum disimpan sampai preview dikonfirmasi.</p>
    <div class="flex gap-2"><x-crm.button type="submit" variant="primary" accent="sales">Buat Preview</x-crm.button><x-crm.button href="{{ route('konsumen-progress.index') }}" variant="secondary">Batal</x-crm.button></div>
</form>
@push('scripts')
<script>
const branch = document.getElementById('branch_id'); const project = document.getElementById('project_id');
branch.addEventListener('change', async () => { project.innerHTML = '<option value="">Memuat proyek...</option>'; const response = await fetch(`{{ route('consumer-import.projects') }}?branch_id=${branch.value}`); const items = await response.json(); project.innerHTML = '<option value="">Pilih proyek</option>' + items.map(item => `<option value="${item.id}">${item.project_name}</option>`).join(''); });
</script>
@endpush
@endsection
