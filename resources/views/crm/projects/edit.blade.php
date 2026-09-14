@extends('layouts.crm')

@section('title', 'Edit Proyek - Oasis CRM')

@section('content')
    <x-crm.page-header color="#5d8e8e" title="Edit Proyek" />

    <div class="border-2 border-black bg-white">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">
            Form Edit Proyek
        </div>
        <div class="p-4 sm:p-6">
            <form method="POST" action="{{ route('projects.update', ['project' => $project->id]) }}" class="space-y-4">
                @csrf
                @method('PUT')
                <input type="hidden" name="expected_updated_at" value="{{ old('expected_updated_at', app(\App\Services\OptimisticLockService::class)->token($project)) }}">

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang</label>
                    <select name="branch_id" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                        <option value="">— Tidak Terikat Cabang —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ old('branch_id', $project->branch_id) == $b->id ? 'selected' : '' }} @if(str_contains(mb_strtolower($b->name), 'pusat')) style="color:#b8860b;font-weight:700;background:#fff3b0" @endif>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Nama Proyek</label>
                    <input type="text" name="project_name" value="{{ old('project_name', $project->project_name) }}"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('project_name') border-[#e91d2a] @enderror">
                    @error('project_name') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="sheet-project-name" class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Identitas Proyek Spreadsheet</label>
                    @if($sheetOptionsWarning)<p class="border border-[#b8860b] bg-[#fff3b0] px-3 py-2 text-xs font-[Helvetica] mb-2">{{ $sheetOptionsWarning }}</p>@endif
                    <select id="sheet-project-name" name="sheet_project_name" class="w-full border-2 border-black px-3 py-2 text-sm bg-white rounded-none"><option value="">Gunakan nama proyek persis</option>@foreach($projectOptions as $option)<option value="{{ $option }}" @selected(old('sheet_project_name', $project->sheet_project_name) === $option)>{{ $option }}</option>@endforeach</select>
                    @error('sheet_project_name') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Status</label>
                    <p class="text-sm font-['Times_New_Roman']">{{ $project->is_active ? 'Aktif' : 'Nonaktif' }}</p>
                    @if(! $project->is_active)<p class="text-xs font-[Helvetica] mt-1">Proyek nonaktif tetap nonaktif setelah disimpan. Reaktivasi tidak tersedia.</p>@endif
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="bg-black text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                        Simpan
                    </button>
                    <a href="{{ route('projects.index') }}" class="bg-white text-black px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                        Batal
                    </a>
                </div>
            </form>

            @if($project->is_active)
            <div class="border-t-2 border-black mt-6 pt-4">
                <form method="POST" action="{{ route('projects.destroy', ['project' => $project->id]) }}"
                      onsubmit="return confirm('Nonaktifkan proyek ini?')">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="expected_updated_at" value="{{ app(\App\Services\OptimisticLockService::class)->token($project) }}">
                    <button type="submit" class="bg-[#e91d2a] text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-red-600">
                        Nonaktifkan Proyek
                    </button>
                </form>
            </div>
            @endif
        </div>
    </div>
@endsection
