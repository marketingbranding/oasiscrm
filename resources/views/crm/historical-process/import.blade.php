@extends('layouts.crm')
@section('title', 'Impor Proses Histori Database Master 2026 - Oasis CRM')
@section('content')
<x-crm.page-header variant="canonical" eyebrow="Konsumen Progress" title="Impor Proses Histori" description="Tempel TSV dari tab Database Master 2026. Preview tidak mengubah data. Hanya Super Admin." />
<form method="POST" action="{{ route('historical-process.import.preview') }}" class="grid max-w-4xl gap-4 border-2 border-black bg-white p-4">@csrf
    <x-crm.field label="Cabang" for="branch_id" required><select id="branch_id" name="branch_id" class="crm-control" required><option value="">Pilih cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select><x-crm.input-error :messages="$errors->get('branch_id')" /></x-crm.field>
    <x-crm.field label="Data TSV" for="tsv" required><textarea id="tsv" name="tsv" rows="16" class="crm-control font-mono" maxlength="262144" required placeholder="Tempel baris dari satu tab tahap proses...">{{ old('tsv') }}</textarea><x-crm.input-error :messages="$errors->get('tsv')" /></x-crm.field>
    <div class="text-sm">
        <p class="font-bold uppercase">Tahap yang dikenali (header harus tepat):</p>
        <ul class="ml-4 list-disc space-y-1">
            @foreach($stageLabels as $key => $label)
                <li><strong>{{ $label }}</strong> — rantai ID: {{ $key }}</li>
            @endforeach
        </ul>
        <p class="mt-2">Rantai ID: id_kons → id_psjb → id_berkas → no_sp3k → id_ppjb_dev → no_ppjb_akad → no_bast. Tahap lanjutan hanya mengikuti ID kanonik; kavling tidak dipakai untuk resolusi.</p>
    </div>
    <div class="flex gap-2"><x-crm.button type="submit" variant="primary" accent="consumer-progress">Buat Preview</x-crm.button><x-crm.button href="{{ route('konsumen-progress.index') }}" variant="secondary">Batal</x-crm.button></div>
</form>
@endsection