@extends('layouts.crm')

@section('title', 'Input Daily Leads - Oasis CRM')

@section('content')
    <div class="bg-[#e6915d] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Input Daily Leads</h1>
    </div>

    <div class="border-2 border-black bg-white">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">
            Form Input Harian
        </div>
        <div class="p-4 sm:p-6">
            <form method="POST" action="{{ route('lead-daily.store') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Event</label>
                    <select name="lead_event_id" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('lead_event_id') border-[#e91d2a] @enderror">
                        <option value="">— Pilih Event —</option>
                        @foreach($events as $e)
                            <option value="{{ $e->id }}" data-branch="{{ $e->branch_id }}" {{ old('lead_event_id') == $e->id ? 'selected' : '' }}>
                                {{ $e->event_id ?? '#' . $e->id }} — {{ $e->project_name }} ({{ $e->lead_source }}) — {{ $e->branch->name ?? '' }}
                            </option>
                        @endforeach
                    </select>
                    @error('lead_event_id') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <input type="hidden" name="branch_id" value="{{ old('branch_id') }}">

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tanggal</label>
                    <input type="date" name="date" value="{{ old('date', date('Y-m-d')) }}"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('date') border-[#e91d2a] @enderror">
                    @error('date') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Leads Didapat</label>
                    <input type="number" name="leads_count" value="{{ old('leads_count', 0) }}" min="0"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('leads_count') border-[#e91d2a] @enderror">
                    @error('leads_count') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="bg-black text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                        Simpan
                    </button>
                    <a href="{{ route('lead-daily.index') }}" class="bg-white text-black px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                        Batal
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection
