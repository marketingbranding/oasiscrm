@extends('layouts.crm')

@section('title', 'Edit Sumber Lead - Oasis CRM')

@section('content')
    <div class="bg-[#5d8e8e] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Edit Sumber Lead</h1>
    </div>

    <div class="border-2 border-black bg-white">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">
            Form Edit Sumber Lead
        </div>
        <div class="p-4 sm:p-6">
            <form method="POST" action="{{ route('lead-sources.update', ['lead_source' => $leadSource->id]) }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Nama Sumber Lead</label>
                    <input type="text" name="name" value="{{ old('name', $leadSource->name) }}"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('name') border-[#e91d2a] @enderror">
                    @error('name') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Status</label>
                    <select name="is_active" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                        <option value="1" {{ old('is_active', $leadSource->is_active) == '1' ? 'selected' : '' }}>Aktif</option>
                        <option value="0" {{ old('is_active', $leadSource->is_active) === '0' ? 'selected' : '' }}>Nonaktif</option>
                    </select>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="bg-black text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                        Simpan
                    </button>
                    <a href="{{ route('lead-sources.index') }}" class="bg-white text-black px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                        Batal
                    </a>
                </div>
            </form>

            <div class="border-t-2 border-black mt-6 pt-4">
                <form method="POST" action="{{ route('lead-sources.destroy', $leadSource->id) }}"
                      onsubmit="return confirm('Hapus sumber lead ini?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="bg-[#e91d2a] text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-red-600">
                        Hapus Sumber Lead
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
