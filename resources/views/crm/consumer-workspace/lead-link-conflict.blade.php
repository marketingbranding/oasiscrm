@extends('layouts.crm')

@section('title', 'Tinjau Konflik Penautan Lead - Oasis CRM')

@section('content')
    <x-crm.page-header variant="canonical" eyebrow="Sales" title="Tinjau Konflik Penautan Lead" description="Pilih keputusan sumber atau tujuan. Alasan wajib dan dicatat di audit.">
        <x-slot:actions><a class="crm-button crm-button--secondary" href="{{ route('consumer-database.workspace.lead-link-conflicts') }}">Daftar konflik</a></x-slot:actions>
    </x-crm.page-header>
    <section class="mt-4 grid gap-4 md:grid-cols-2">
        <div class="border-2 border-black bg-white p-4"><h2 class="font-bold">Keputusan sumber</h2><p class="mt-2">Status: {{ $conflict->source_status ?: '—' }}</p><p>Lead: {{ $conflict->source_lead_id ?: '—' }}</p></div>
        <div class="border-2 border-black bg-white p-4"><h2 class="font-bold">Keputusan tujuan</h2><p class="mt-2">Status: {{ $conflict->target_status ?: '—' }}</p><p>Lead: {{ $conflict->target_lead_id ?: '—' }}</p></div>
    </section>
    <form method="POST" action="{{ route('consumer-database.workspace.lead-link-conflict.resolve', $conflict->id) }}" class="mt-4 grid gap-3 border-2 border-black bg-white p-4">
        @csrf
        <fieldset><legend class="crm-type-label">Keputusan yang dipakai <span aria-hidden="true">*</span></legend><label class="mr-4"><input type="radio" name="decision" value="source" required> Sumber</label><label><input type="radio" name="decision" value="target"> Tujuan</label></fieldset>
        <div><label for="conflict-reason" class="crm-type-label">Alasan <span aria-hidden="true">*</span></label><textarea id="conflict-reason" name="reason" required maxlength="2000" class="crm-control mt-1 w-full">{{ old('reason') }}</textarea>@error('reason')<p class="text-sm text-red-700">{{ $message }}</p>@enderror</div>
        <button class="crm-button crm-button--primary w-fit">Simpan tinjauan</button>
    </form>
@endsection
