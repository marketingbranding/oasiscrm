@extends('layouts.crm')

@section('title', 'Import Kavling - ' . $project->project_name . ' - Oasis CRM')

@section('content')
    <x-crm.page-header color="#5d8e8e" title="Import Kavling — {{ $project->project_name }}" />

    <div class="bg-white border-2 border-black p-4">
        <form method="POST" action="{{ route('kavlings.bulk-store', ['project' => $project->id]) }}">
            @csrf

            <label class="block text-sm font-[Helvetica] font-bold mb-2">Paste daftar kavling (satu per baris):</label>

            <textarea name="list" rows="20" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] rounded-none focus:outline-none" placeholder="Marison Regency Jepara 2-AA01&#10;Marison Regency Jepara 2-AA02&#10;Marison Regency Jepara 2-AA03&#10;...">{{ old('list') }}</textarea>

            @error('list')
                <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
            @enderror

            <p class="text-xs text-gray-500 mt-2">
                Format: <code>Nama Proyek-KODE</code> (contoh: Marison Regency Jepara 2-AA01).
                Baris kosong akan dilewati.
            </p>

            <div class="flex items-center gap-2 mt-4">
                <a href="{{ route('kavlings.index', ['project' => $project->id]) }}" class="bg-white text-black px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                    Batal
                </a>
                <button type="submit" class="bg-[#5d8e8e] text-white px-4 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#4a7a7a]">
                    Import
                </button>
            </div>
        </form>
    </div>
@endsection
