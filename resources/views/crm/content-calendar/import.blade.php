@extends('layouts.crm')

@section('title', 'Import Work Planner - Oasis CRM')

@section('content')
    <x-crm.page-header color="#b3bd95" title="Import Work Planner" />

    <div class="bg-white border-2 border-black p-4 mb-6">
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
            <a href="{{ route('content-calendar.export-template') }}"
               class="bg-white text-black px-4 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                ↓ Download Template
            </a>
        </div>

        <form method="POST" action="{{ route('content-calendar.import-store') }}" enctype="multipart/form-data">
            @csrf

            <label class="block text-sm font-[Helvetica] font-bold mb-2">Pilih File XLSX:</label>
            <input type="file" name="file" accept=".xlsx"
                   class="block w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none file:border-0 file:bg-[#b3bd95] file:text-black file:px-3 file:py-1 file:mr-3 file:font-[Helvetica] file:font-bold file:cursor-pointer">

            @error('file')
                <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
            @enderror

            @if(session('import_errors') && count(session('import_errors')) > 0)
                <div class="mt-4 border-2 border-[#c0392b] bg-red-50 p-3">
                    <p class="text-sm font-[Helvetica] font-bold text-[#c0392b] mb-1">{{ count(session('import_errors')) }} baris dilewati:</p>
                    <ul class="list-disc list-inside text-xs text-red-700 space-y-0.5 max-h-40 overflow-y-auto">
                        @foreach(session('import_errors') as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex items-center gap-2 mt-4">
                <a href="{{ route('content-calendar.index') }}"
                   class="bg-white text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                    Batal
                </a>
                <button type="submit"
                        class="bg-[#b3bd95] text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#9eaa7a]">
                    ↑ Import
                </button>
            </div>
        </form>
    </div>
@endsection
