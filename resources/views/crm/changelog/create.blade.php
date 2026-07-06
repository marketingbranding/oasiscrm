@extends('layouts.crm')

@section('title', 'Tambah Changelog - Oasis CRM')

@section('content')
    <div class="bg-[#8c9ae0] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Tambah Changelog</h1>
    </div>

    <div class="border-2 border-black bg-white p-5 max-w-2xl">
        <form method="POST" action="{{ route('changelogs.store') }}">
            @csrf
            <div class="mb-4">
                <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Kategori</label>
                <select name="category" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                    <option value="added" {{ old('category') === 'added' ? 'selected' : '' }}>ADDED — Fitur baru</option>
                    <option value="fixed" {{ old('category') === 'fixed' ? 'selected' : '' }}>FIXED — Perbaikan bug</option>
                    <option value="changed" {{ old('category') === 'changed' ? 'selected' : '' }}>CHANGED — Perubahan</option>
                    <option value="removed" {{ old('category') === 'removed' ? 'selected' : '' }}>REMOVED — Dihapus</option>
                </select>
                @error('category') <p class="text-[#c0392b] text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="mb-4">
                <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Judul</label>
                <input name="title" value="{{ old('title') }}" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] rounded-none">
                @error('title') <p class="text-[#c0392b] text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="mb-4">
                <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Versi <span class="font-normal text-gray-400">(opsional)</span></label>
                <input name="version" value="{{ old('version') }}" placeholder="1.0.0" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] rounded-none">
                @error('version') <p class="text-[#c0392b] text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="mb-4">
                <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Deskripsi <span class="font-normal text-gray-400">(opsional)</span></label>
                <textarea name="description" rows="4" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] rounded-none">{{ old('description') }}</textarea>
                @error('description') <p class="text-[#c0392b] text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="bg-black text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">Simpan</button>
                <a href="{{ route('changelogs.index') }}" class="bg-white text-black px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">Batal</a>
            </div>
        </form>
    </div>
@endsection
