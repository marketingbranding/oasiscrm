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
                    <select name="branch_id" class="searchable-select w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('branch_id') border-[#e91d2a] @enderror">
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ old('branch_id', $event->branch_id) == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>
                @endif

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek</label>
                    <select name="project_name" class="searchable-select w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('project_name') border-[#e91d2a] @enderror">
                        <option value="">— Pilih Proyek —</option>
                        @foreach($projects as $p)
                            <option value="{{ $p->project_name }}" data-branch="{{ $p->branch_id }}" {{ old('project_name', $event->project_name) === $p->project_name ? 'selected' : '' }}>{{ $p->project_name }}</option>
                        @endforeach
                    </select>
                    @error('project_name') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Sumber Lead</label>
                    <select name="lead_source" class="searchable-select w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('lead_source') border-[#e91d2a] @enderror">
                        <option value="">— Pilih Sumber —</option>
                        @foreach($sources as $src)
                            <option value="{{ $src }}" {{ old('lead_source', $event->lead_source) === $src ? 'selected' : '' }}>{{ $src }}</option>
                        @endforeach
                    </select>
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
            function initSearchableSelects() {
                document.querySelectorAll('select.searchable-select').forEach(function(select) {
                    if (select.dataset.searchableInitialized) return;
                    select.dataset.searchableInitialized = '1';

                    var allOptions = [];
                    for (var i = 1; i < select.options.length; i++) {
                        allOptions.push(select.options[i]);
                    }

                    var wrapper = document.createElement('div');
                    wrapper.className = 'relative';

                    var searchInput = document.createElement('input');
                    searchInput.type = 'text';
                    searchInput.className = 'w-full border-b-2 border-black px-3 py-1.5 text-sm font-[\'Times_New_Roman\'] bg-gray-50 rounded-none mb-1';
                    searchInput.placeholder = 'Cari...';

                    select.parentNode.insertBefore(wrapper, select);
                    wrapper.appendChild(searchInput);
                    wrapper.appendChild(select);

                    searchInput.addEventListener('input', function() {
                        var filter = this.value.toLowerCase();
                        for (var j = select.options.length - 1; j >= 1; j--) {
                            select.remove(j);
                        }
                        for (var k = 0; k < allOptions.length; k++) {
                            if (allOptions[k].text.toLowerCase().indexOf(filter) !== -1) {
                                select.add(allOptions[k]);
                            }
                        }
                    });

                    select.addEventListener('change', function() {
                        searchInput.value = '';
                        for (var j = select.options.length - 1; j >= 1; j--) {
                            select.remove(j);
                        }
                        for (var k = 0; k < allOptions.length; k++) {
                            select.add(allOptions[k]);
                        }
                    });
                });
            }

            function filterByBranch() {
                var branchSelect = document.querySelector('[name="branch_id"]');
                if (!branchSelect) return;

                var projectSelect = document.querySelector('[name="project_name"]');

                function doFilter() {
                    var branchId = branchSelect.value;

                    if (projectSelect) {
                        var projectOptions = projectSelect.searchableAllOptions || [];
                        if (!projectOptions.length) {
                            for (var i = 1; i < projectSelect.options.length; i++) {
                                projectOptions.push(projectSelect.options[i]);
                            }
                            projectSelect.searchableAllOptions = projectOptions;
                        }
                        for (var j = projectSelect.options.length - 1; j >= 1; j--) {
                            projectSelect.remove(j);
                        }
                        for (var k = 0; k < projectOptions.length; k++) {
                            var optBranch = projectOptions[k].getAttribute('data-branch');
                            if (!branchId || !optBranch || optBranch === branchId) {
                                projectSelect.add(projectOptions[k]);
                            }
                        }
                        var searchInput = projectSelect.previousElementSibling;
                        if (searchInput) searchInput.value = '';
                    }
                }

                branchSelect.addEventListener('change', doFilter);
                doFilter();
            }

            document.addEventListener('DOMContentLoaded', function() {
                initSearchableSelects();
                filterByBranch();
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
