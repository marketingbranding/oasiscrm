@extends('layouts.crm')
@section('title', 'Import Proses Historis - Oasis CRM')
@section('content')
<x-crm.page-header variant="canonical" eyebrow="Konsumen Progress" title="Import Proses Historis" description="Tempel data proses historis dari spreadsheet ke data konsumen lokal. Tidak mengubah data Google atau alur operasional." />
<form method="POST" action="{{ route('historical-process-import.preview') }}" class="grid max-w-5xl gap-4 border-2 border-black bg-white p-4">
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
    <x-crm.field label="Jenis Proses" for="process_type" required>
        <select id="process_type" name="process_type" class="crm-control" required>
            <option value="">Pilih jenis proses</option>
            @foreach($processTypes as $key => $label)<option value="{{ $key }}" @selected(old('process_type') === $key)>{{ $label }}</option>@endforeach
        </select>
        <x-crm.input-error :messages="$errors->get('process_type')" />
    </x-crm.field>
    <x-crm.field label="Data TSV dari Spreadsheet" for="tsv" required>
        <textarea id="tsv" name="tsv" rows="18" class="crm-control font-mono" maxlength="262144" required placeholder="id_kavling&#9;tanggal&#9;status&#9;keterangan&#10;KAV-001&#9;2025-06-15&#9;Lolos&#9;BI checking selesai&#10;KAV-002&#9;2025-06-16&#9;Tidak Lolos&#9;">{{ old('tsv') }}</textarea>
        <x-crm.input-error :messages="$errors->get('tsv')" />
    </x-crm.field>
    <p class="text-sm text-gray-600">Salin header beserta data proses langsung dari spreadsheet. Minimal <strong>ID Kavling</strong> harus terisi. Kolom tanggal: YYYY-MM-DD atau M/D/YYYY. Proses Bank memerlukan kolom Bank dan Status Bank.</p>
    <p class="text-sm">Sistem mencocokkan baris dengan aplikasi lokal melalui identitas provenance yang sudah terekam (kolom ID eksternal, atau kombinasi unik data konsumen dari impor sebelumnya) di cabang dan proyek terpilih. Kavling saja tidak cukup untuk menentukan aplikasi; baris tanpa identitas yang cocok diklasifikasikan sebagai UNRESOLVED.</p>
    <div class="flex gap-2"><x-crm.button type="submit" variant="primary" accent="sales">Buat Preview</x-crm.button><x-crm.button href="{{ route('konsumen-progress.index') }}" variant="secondary">Batal</x-crm.button></div>
</form>
@push('scripts')
<script>
const branch = document.getElementById('branch_id'); const project = document.getElementById('project_id');
branch.addEventListener('change', async () => { project.innerHTML = '<option value="">Memuat proyek...</option>'; const response = await fetch(`{{ route('historical-process-import.projects') }}?branch_id=${branch.value}`); const items = await response.json(); project.innerHTML = '<option value="">Pilih proyek</option>' + items.map(item => `<option value="${item.id}">${item.project_name}</option>`).join(''); });
</script>
@endpush
@endsection
