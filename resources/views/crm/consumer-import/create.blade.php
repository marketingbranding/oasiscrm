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
        <textarea id="tsv" name="tsv" rows="18" class="crm-control font-mono" maxlength="262144" required placeholder="id_kavling&#9;no_ktp&#9;nama_konsumen&#9;tanggal_lahir&#9;pekerjaan&#9;detail_pekerjaan&#9;umur&#9;alamat&#9;kelurahan&#9;kecamatan&#9;kabupaten/kota&#9;no_hp&#9;nama_kondar&#9;no_hp_kondar&#9;status_cash&#9;Status&#9;keterangan">{{ old('tsv') }}</textarea>
        <x-crm.input-error :messages="$errors->get('tsv')" />
    </x-crm.field>
    <p class="text-sm text-gray-600">Format mengikuti spreadsheet Database Master.</p>
    <p class="text-sm">Salin header beserta data konsumen langsung dari spreadsheet Database Master, lalu tempel di sini. Minimal <strong>Nama Konsumen</strong> harus terisi. Sistem akan memeriksa data terlebih dahulu melalui Preview sebelum disimpan. Tanggal: YYYY-MM-DD atau M/D/YYYY. Format DD/MM/YYYY ambigu dan ditolak.</p>
    <div class="flex gap-2"><x-crm.button type="submit" variant="primary" accent="sales">Buat Preview</x-crm.button><x-crm.button href="{{ route('konsumen-progress.index') }}" variant="secondary">Batal</x-crm.button></div>
</form>
@push('scripts')
<script>
const branch = document.getElementById('branch_id'); const project = document.getElementById('project_id');
branch.addEventListener('change', async () => { project.innerHTML = '<option value="">Memuat proyek...</option>'; const response = await fetch(`{{ route('consumer-import.projects') }}?branch_id=${branch.value}`); const items = await response.json(); project.innerHTML = '<option value="">Pilih proyek</option>' + items.map(item => `<option value="${item.id}">${item.project_name}</option>`).join(''); });
</script>
@endpush
@endsection
