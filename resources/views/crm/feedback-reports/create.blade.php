@extends('layouts.crm')

@section('title', 'Buat Laporan - Oasis CRM')

@section('content')
    <x-crm.page-header color="#c0392b" title="Buat Laporan / Masukan" />

    <div class="border-2 border-black bg-white max-w-2xl mx-auto">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">Form Laporan</div>
        <div class="p-4 sm:p-6">
            <form method="POST" action="{{ route('feedback-reports.store') }}" class="space-y-4">
                @csrf

                @if(Auth::user()->canViewAllBranches())
                <div>
                    <label class="block text-xs font-[Helvetica] font-bold mb-1">Cabang</label>
                    <select name="branch_id" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('branch_id') border-[#c0392b] @enderror">
                        <option value="">— Pilih Cabang —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ old('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <p class="text-[#c0392b] text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                @endif

                <div>
                    <label class="block text-xs font-[Helvetica] font-bold mb-1">Tipe</label>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2 text-sm font-['Times_New_Roman'] cursor-pointer">
                            <input type="radio" name="type" value="masukan" {{ old('type', 'masukan') === 'masukan' ? 'checked' : '' }} class="cursor-pointer">
                            <span class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black bg-[#e6915d] text-white">MASUKAN</span>
                        </label>
                        <label class="flex items-center gap-2 text-sm font-['Times_New_Roman'] cursor-pointer">
                            <input type="radio" name="type" value="bug" {{ old('type') === 'bug' ? 'checked' : '' }} class="cursor-pointer">
                            <span class="inline-block px-2 py-0.5 text-xs font-[Helvetica] font-bold border border-black bg-[#d77a7a] text-white">BUG</span>
                        </label>
                    </div>
                    @error('type') <p class="text-[#c0392b] text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-[Helvetica] font-bold mb-1">Judul</label>
                    <input name="title" value="{{ old('title') }}" maxlength="255"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('title') border-[#c0392b] @enderror"
                           placeholder="Contoh: Tambah fitur export PDF">
                    @error('title') <p class="text-[#c0392b] text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-[Helvetica] font-bold mb-1">Deskripsi</label>
                    <textarea name="description" rows="5" maxlength="5000"
                              class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none resize-y @error('description') border-[#c0392b] @enderror"
                              placeholder="Jelaskan secara detail masukan atau bug yang ditemukan...">{{ old('description') }}</textarea>
                    @error('description') <p class="text-[#c0392b] text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="bg-[#c0392b] text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-[#a93226]">
                        Kirim Laporan
                    </button>
                    <a href="{{ route('feedback-reports.index', array_filter(request()->only(['branch_id', 'type', 'status']))) }}"
                       class="bg-white text-black px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                        Batal
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection
