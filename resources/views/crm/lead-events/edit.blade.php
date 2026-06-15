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
                @php $branchMap = $branches->pluck('id', 'name'); @endphp
                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang</label>
                    <input type="text" name="branch_name" value="{{ old('branch_name', $event->branch->name ?? '') }}" list="branch-list" autocomplete="off"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('branch_id') border-[#e91d2a] @enderror"
                           placeholder="Ketik atau pilih cabang...">
                    <input type="hidden" name="branch_id" value="{{ old('branch_id', $event->branch_id) }}">
                    <datalist id="branch-list">
                        @foreach($branches as $b)
                            <option value="{{ $b->name }}" data-id="{{ $b->id }}">
                        @endforeach
                    </datalist>
                    @error('branch_id') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>
                @endif

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek</label>
                    <input type="text" name="project_name" value="{{ old('project_name', $event->project_name) }}" list="project-list" autocomplete="off"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('project_name') border-[#e91d2a] @enderror"
                           placeholder="Ketik atau pilih proyek...">
                    <datalist id="project-list">
                        @foreach($projects as $p)
                            <option value="{{ $p->project_name }}" data-branch="{{ $p->branch_id }}">
                        @endforeach
                    </datalist>
                    @error('project_name') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Sumber Lead</label>
                    <input type="text" name="lead_source" value="{{ old('lead_source', $event->lead_source) }}" list="source-list" autocomplete="off"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('lead_source') border-[#e91d2a] @enderror"
                           placeholder="Ketik atau pilih sumber...">
                    <datalist id="source-list">
                        @foreach($sources as $src)
                            <option value="{{ $src }}">
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

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Anggaran Total</label>
                    <input type="number" name="total_budget" value="{{ old('total_budget', $event->total_budget) }}"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('total_budget') border-[#e91d2a] @enderror">
                    @error('total_budget') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
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
            var projectData = [
                @foreach($projects as $p)
                { name: {{ json_encode($p->project_name) }}, branch: {{ json_encode($p->branch_id) }} },
                @endforeach
            ];

            var branchMap = {};
            @if(Auth::user()->canViewAllBranches() && isset($branches))
                @foreach($branches as $b)
                branchMap[{{ json_encode($b->name) }}] = '{{ $b->id }}';
                @endforeach
            @endif

            function rebuildProjectList(branchId) {
                var list = document.getElementById('project-list');
                list.innerHTML = '';
                for (var i = 0; i < projectData.length; i++) {
                    if (!branchId || !projectData[i].branch || projectData[i].branch === branchId) {
                        var opt = document.createElement('option');
                        opt.value = projectData[i].name;
                        list.appendChild(opt);
                    }
                }
            }

            document.addEventListener('DOMContentLoaded', function() {
                var branchInput = document.querySelector('[name="branch_name"]');
                var branchHidden = document.querySelector('[name="branch_id"]');

                if (branchInput && branchHidden) {
                    branchInput.addEventListener('input', function() {
                        var id = branchMap[this.value];
                        branchHidden.value = id || '';
                        rebuildProjectList(id || '');
                    });
                }

                var initialBranch = branchHidden ? branchHidden.value : '';
                if (!initialBranch && !branchInput) {
                    rebuildProjectList('');
                } else if (initialBranch) {
                    rebuildProjectList(initialBranch);
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
