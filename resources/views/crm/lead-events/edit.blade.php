@extends('layouts.crm')

@section('title', 'Edit Event - Oasis CRM')

@section('content')
    <div class="bg-[#e6915d] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Edit Event</h1>
    </div>

    <div class="border-2 border-black bg-white">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">
            Form Edit Event
        </div>
        <div class="p-4 sm:p-6">
            <form method="POST" action="{{ route('lead-events.update', ['lead_event' => $event->id]) }}" class="space-y-4">
                @csrf
                @method('PUT')

                @if(Auth::user()->canViewAllBranches() && isset($branches) && $branches->count() > 0)
                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang</label>
                    <select name="branch_id" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('branch_id') border-[#e91d2a] @enderror">
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ old('branch_id', $event->branch_id) == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>
                @endif

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek</label>
                    <input type="text" name="project_name" value="{{ old('project_name', $event->project_name) }}" list="project-list"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('project_name') border-[#e91d2a] @enderror">
                    <datalist id="project-list">
                        @foreach($projects as $p)
                            <option value="{{ $p->project_name }}" data-branch="{{ $p->branch_id }}">
                        @endforeach
                    </datalist>
                    @error('project_name') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Sumber Lead</label>
                    <input type="text" name="lead_source" value="{{ old('lead_source', $event->lead_source) }}" list="source-list"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('lead_source') border-[#e91d2a] @enderror">
                    <datalist id="source-list">
                        @foreach($projects as $p)
                            @if($p->lead_source)
                                <option value="{{ $p->lead_source }}" data-branch="{{ $p->branch_id }}">
                            @endif
                        @endforeach
                    </datalist>
                    @error('lead_source') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tanggal Mulai</label>
                        <input type="date" name="start_date" value="{{ old('start_date', $event->start_date->format('Y-m-d')) }}"
                               class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('start_date') border-[#e91d2a] @enderror">
                        @error('start_date') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tanggal Selesai</label>
                        <input type="date" name="end_date" value="{{ old('end_date', $event->end_date?->format('Y-m-d')) }}"
                               class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('end_date') border-[#e91d2a] @enderror">
                        @error('end_date') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Anggaran Total</label>
                        <input type="number" name="total_budget" value="{{ old('total_budget', $event->total_budget) }}"
                               class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('total_budget') border-[#e91d2a] @enderror">
                        @error('total_budget') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Target Lead Harian</label>
                        <input type="number" name="daily_target" value="{{ old('daily_target', $event->daily_target) }}"
                               class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('daily_target') border-[#e91d2a] @enderror">
                        @error('daily_target') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Status</label>
                    <select name="status" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                        <option value="berlangsung" {{ old('status', $event->status) === 'berlangsung' ? 'selected' : '' }}>Berlangsung</option>
                        <option value="selesai" {{ old('status', $event->status) === 'selesai' ? 'selected' : '' }}>Selesai</option>
                    </select>
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Catatan</label>
                    <textarea name="notes" rows="3" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none">{{ old('notes', $event->notes) }}</textarea>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="bg-black text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                        Simpan
                    </button>
                    <a href="{{ route('lead-events.index') }}" class="bg-white text-black px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                        Batal
                    </a>
                </div>
            </form>

            <script>
            document.querySelector('[name="branch_id"]')?.addEventListener('change', function() {
                const branchId = this.value;
                document.querySelectorAll('#project-list option, #source-list option').forEach(function(opt) {
                    const optBranch = opt.getAttribute('data-branch');
                    opt.style.display = (!branchId || !optBranch || optBranch === branchId) ? '' : 'none';
                });
            });
            document.addEventListener('DOMContentLoaded', function() {
                const branchSelect = document.querySelector('[name="branch_id"]');
                if (branchSelect) {
                    branchSelect.dispatchEvent(new Event('change'));
                }
            });
            </script>

            <div class="border-t-2 border-black mt-6 pt-4">
                <form method="POST" action="{{ route('lead-events.destroy', ['lead_event' => $event->id]) }}"
                      onsubmit="return confirm('Hapus event ini? Semua data harian terkait akan ikut terhapus.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="bg-[#e91d2a] text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-red-600">
                        Hapus Event
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
