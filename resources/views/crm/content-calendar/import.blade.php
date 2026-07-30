@extends('layouts.crm')

@section('title', 'Import Work Planner - Oasis CRM')

@section('content')
    <x-crm.page-header variant="canonical" eyebrow="Work Planner" title="Import Work Planner" description="Upload XLSX sesuai template Work Planner. Arsitektur import lama tetap dipertahankan.">
        <x-slot:actions>
            <x-crm.button variant="secondary" :href="route('content-calendar.index')">Kembali</x-crm.button>
        </x-slot:actions>
    </x-crm.page-header>

    <x-crm.card variant="default" padding="lg" class="mb-6">
        <div class="text-sm font-['Times_New_Roman'] mb-4 leading-relaxed">
            <p class="mb-2"><strong>Petunjuk:</strong></p>
            <ol class="list-decimal list-inside space-y-1 text-xs">
                <li>Download template XLSX terlebih dahulu.</li>
                <li>Isi data sesuai kolom yang tersedia.</li>
                <li>Upload file yang sudah diisi.</li>
            </ol>
            <p class="mt-2 text-xs text-gray-600">
                Kolom wajib: <strong>Tipe</strong>, <strong>Judul</strong>, dan <strong>Deadline/Publikasi</strong>.
                Template Task Tracker lama tetap dapat diimpor dan otomatis dianggap sebagai Task Tim.
                Baris dengan data tidak valid akan dilewati.
            </p>
        </div>

        <div class="flex items-center gap-3 mb-6">
            <x-crm.button variant="secondary" :href="route('content-calendar.export-template')">Download Template XLSX</x-crm.button>
        </div>

        <form method="POST" action="{{ route('content-calendar.import-store') }}" enctype="multipart/form-data">
            @csrf

            <x-crm.field label="Pilih File XLSX" for="planner-import-file" required :error="$errors->first('file')">
                <input id="planner-import-file" type="file" name="file" accept=".xlsx"
                       class="block w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none file:border-0 file:bg-[#b3bd95] file:text-black file:px-3 file:py-1 file:mr-3 file:font-[Helvetica] file:font-bold file:cursor-pointer">
            </x-crm.field>

            @if(session('import_errors') && count(session('import_errors')) > 0)
                <x-crm.alert variant="warning" title="{{ count(session('import_errors')) }} baris dilewati" class="mt-4">
                    <ul class="list-disc list-inside text-xs text-red-700 space-y-0.5 max-h-40 overflow-y-auto">
                        @foreach(session('import_errors') as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </x-crm.alert>
            @endif

            <div class="flex items-center gap-2 mt-4">
                <x-crm.button variant="secondary" :href="route('content-calendar.index')">Batal</x-crm.button>
                <x-crm.button type="submit" variant="primary" accent="planner">Import</x-crm.button>
            </div>
        </form>
    </x-crm.card>
@endsection
