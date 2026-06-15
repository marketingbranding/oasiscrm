@extends('layouts.crm')

@section('title', 'Tambah Event - Oasis CRM')

@section('content')
    <div class="bg-[#e6915d] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Tambah Event</h1>
    </div>

    <div class="border-2 border-black bg-white">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">
            Form Event Baru
        </div>
        <div class="p-4 sm:p-6">
            <form method="POST" action="{{ route('lead-events.store') }}" class="space-y-4">
                @csrf

                @if(Auth::user()->canViewAllBranches() && isset($branches) && $branches->count() > 0)
                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang</label>
                    <div class="select-wrapper relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between {{ $errors->has('branch_id') ? 'border-[#e91d2a]' : 'border-black' }}" tabindex="0">
                            <span class="select-text">— Pilih Cabang —</span>
                            <span class="select-arrow text-xs">▼</span>
                        </div>
                        <div class="select-dropdown hidden absolute top-full left-0 right-0 z-50 border-2 border-black bg-white">
                            <input type="text" class="select-search w-full border-b-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-gray-50 outline-none" placeholder="Cari...">
                            <ul class="select-options list-none m-0 p-0 max-h-48 overflow-y-auto">
                                @foreach($branches as $b)
                                    <li data-value="{{ $b->id }}" class="px-3 py-1.5 text-sm font-['Times_New_Roman'] cursor-pointer hover:bg-[#e6915d] hover:text-white {{ old('branch_id') == $b->id ? 'bg-[#e6915d] text-white' : '' }}">{{ $b->name }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <select name="branch_id" class="hidden">
                            <option value="">— Pilih Cabang —</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ old('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('branch_id') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>
                @endif

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek</label>
                    <div class="select-wrapper relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between {{ $errors->has('project_name') ? 'border-[#e91d2a]' : 'border-black' }}" tabindex="0">
                            <span class="select-text">— Pilih Proyek —</span>
                            <span class="select-arrow text-xs">▼</span>
                        </div>
                        <div class="select-dropdown hidden absolute top-full left-0 right-0 z-50 border-2 border-black bg-white">
                            <input type="text" class="select-search w-full border-b-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-gray-50 outline-none" placeholder="Cari...">
                            <ul class="select-options list-none m-0 p-0 max-h-48 overflow-y-auto">
                                @foreach($projects as $p)
                                    <li data-value="{{ $p->project_name }}" data-branch="{{ $p->branch_id }}" class="px-3 py-1.5 text-sm font-['Times_New_Roman'] cursor-pointer hover:bg-[#e6915d] hover:text-white {{ old('project_name') === $p->project_name ? 'bg-[#e6915d] text-white' : '' }}">{{ $p->project_name }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <select name="project_name" class="hidden">
                            <option value="">— Pilih Proyek —</option>
                            @foreach($projects as $p)
                                <option value="{{ $p->project_name }}" data-branch="{{ $p->branch_id }}" {{ old('project_name') === $p->project_name ? 'selected' : '' }}>{{ $p->project_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('project_name') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Sumber Lead</label>
                    <div class="select-wrapper relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between {{ $errors->has('lead_source') ? 'border-[#e91d2a]' : 'border-black' }}" tabindex="0">
                            <span class="select-text">— Pilih Sumber —</span>
                            <span class="select-arrow text-xs">▼</span>
                        </div>
                        <div class="select-dropdown hidden absolute top-full left-0 right-0 z-50 border-2 border-black bg-white">
                            <input type="text" class="select-search w-full border-b-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-gray-50 outline-none" placeholder="Cari...">
                            <ul class="select-options list-none m-0 p-0 max-h-48 overflow-y-auto">
                                @foreach($sources as $src)
                                    <li data-value="{{ $src }}" class="px-3 py-1.5 text-sm font-['Times_New_Roman'] cursor-pointer hover:bg-[#e6915d] hover:text-white {{ old('lead_source') === $src ? 'bg-[#e6915d] text-white' : '' }}">{{ $src }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <select name="lead_source" class="hidden">
                            <option value="">— Pilih Sumber —</option>
                            @foreach($sources as $src)
                                <option value="{{ $src }}" {{ old('lead_source') === $src ? 'selected' : '' }}>{{ $src }}</option>
                            @endforeach
                        </select>
                    </div>
                    @error('lead_source') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tanggal Mulai</label>
                        <input type="date" name="start_date" value="{{ old('start_date') }}"
                               class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('start_date') border-[#e91d2a] @enderror">
                        @error('start_date') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tanggal Selesai</label>
                        <input type="date" name="end_date" value="{{ old('end_date') }}"
                               class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('end_date') border-[#e91d2a] @enderror">
                        @error('end_date') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Anggaran Total</label>
                    <input type="number" name="total_budget" value="{{ old('total_budget') }}"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('total_budget') border-[#e91d2a] @enderror">
                    @error('total_budget') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Status</label>
                    <select name="status" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                        <option value="berlangsung" {{ old('status', 'berlangsung') === 'berlangsung' ? 'selected' : '' }}>Berlangsung</option>
                        <option value="selesai" {{ old('status') === 'selesai' ? 'selected' : '' }}>Selesai</option>
                    </select>
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Catatan</label>
                    <textarea name="notes" rows="3" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none">{{ old('notes') }}</textarea>
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
                { name: {{ json_encode($p->project_name) }}, branch: '{{ $p->branch_id }}' },
                @endforeach
            ];

            function initSelectWrapper(wrapper) {
                if (wrapper.dataset.initialized) return;
                wrapper.dataset.initialized = '1';

                var display = wrapper.querySelector('.select-display');
                var textEl = wrapper.querySelector('.select-text');
                var arrow = wrapper.querySelector('.select-arrow');
                var dropdown = wrapper.querySelector('.select-dropdown');
                var search = wrapper.querySelector('.select-search');
                var list = wrapper.querySelector('.select-options');
                var select = wrapper.querySelector('select');

                function syncDisplay() {
                    var idx = select.selectedIndex;
                    textEl.textContent = idx > 0 ? select.options[idx].text : select.options[0].text;
                }

                function openDropdown() {
                    dropdown.classList.remove('hidden');
                    arrow.textContent = '\u25B2';
                    search.value = '';
                    search.focus();
                    list.querySelectorAll('li').forEach(function(li) { li.style.display = ''; });
                }

                function closeDropdown() {
                    dropdown.classList.add('hidden');
                    arrow.textContent = '\u25BC';
                }

                function selectOption(li) {
                    list.querySelectorAll('li').forEach(function(l) {
                        l.classList.remove('bg-[#e6915d]', 'text-white');
                    });
                    li.classList.add('bg-[#e6915d]', 'text-white');
                    textEl.textContent = li.textContent;
                    select.value = li.dataset.value;
                    var evt = new Event('change', { bubbles: true });
                    select.dispatchEvent(evt);
                    closeDropdown();
                }

                display.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (dropdown.classList.contains('hidden')) openDropdown();
                    else closeDropdown();
                });

                search.addEventListener('input', function() {
                    var q = this.value.toLowerCase();
                    list.querySelectorAll('li').forEach(function(li) {
                        li.style.display = li.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
                    });
                });

                search.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        var visible = list.querySelector('li:not([style*="display: none"])');
                        if (visible && visible.dataset.value) {
                            selectOption(visible);
                        }
                    }
                    if (e.key === 'Escape') closeDropdown();
                });

                list.addEventListener('click', function(e) {
                    var li = e.target.closest('li');
                    if (li) selectOption(li);
                });

                document.addEventListener('click', function(e) {
                    if (!wrapper.contains(e.target)) closeDropdown();
                });

                syncDisplay();
            }

            function rebuildProjectOptions(branchId) {
                var proyekSelect = document.querySelector('[name="project_name"]');
                if (!proyekSelect) return;

                var wrapper = proyekSelect.closest('.select-wrapper');
                var list = wrapper.querySelector('.select-options');
                var displayText = wrapper.querySelector('.select-text');
                var currentVal = proyekSelect.value;

                while (proyekSelect.options.length > 1) proyekSelect.remove(1);

                list.innerHTML = '';
                var placeholderLi = document.createElement('li');
                placeholderLi.dataset.value = '';
                placeholderLi.textContent = '\u2014 Pilih Proyek \u2014';
                placeholderLi.className = 'px-3 py-1.5 text-sm font-[\'Times_New_Roman\']';
                list.appendChild(placeholderLi);

                var hasMatch = false;
                for (var i = 0; i < projectData.length; i++) {
                    if (!branchId || !projectData[i].branch || projectData[i].branch === branchId) {
                        var opt = document.createElement('option');
                        opt.value = projectData[i].name;
                        opt.textContent = projectData[i].name;
                        if (projectData[i].name === currentVal) {
                            opt.selected = true;
                            hasMatch = true;
                        }
                        proyekSelect.add(opt);

                        var li = document.createElement('li');
                        li.dataset.value = projectData[i].name;
                        li.textContent = projectData[i].name;
                        li.className = 'px-3 py-1.5 text-sm font-[\'Times_New_Roman\'] cursor-pointer hover:bg-[#e6915d] hover:text-white';
                        if (projectData[i].name === currentVal) {
                            li.classList.add('bg-[#e6915d]', 'text-white');
                        }
                        list.appendChild(li);
                    }
                }

                displayText.textContent = hasMatch ? currentVal : '\u2014 Pilih Proyek \u2014';
                if (!hasMatch) proyekSelect.value = '';
            }

            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('.select-wrapper').forEach(initSelectWrapper);

                var branchSelect = document.querySelector('[name="branch_id"]');
                if (branchSelect) {
                    branchSelect.addEventListener('change', function() {
                        rebuildProjectOptions(this.value);
                    });
                    var initVal = branchSelect.value;
                    if (initVal) rebuildProjectOptions(initVal);
                }
            });
            </script>
        </div>
    </div>
@endsection
