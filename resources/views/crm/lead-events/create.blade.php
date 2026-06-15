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
                @php $cabangError = $errors->has('branch_id') ? 'border-[#e91d2a]' : 'border-black'; @endphp
                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang</label>
                    <div class="select-wrapper relative" style="position:relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between {{ $cabangError }}" tabindex="0">
                            <span class="select-text">— Pilih Cabang —</span>
                            <span class="select-arrow">▼</span>
                        </div>
                        <div class="select-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;border:2px solid #000;background:#fff">
                            <input type="text" class="select-search" style="width:100%;border-bottom:2px solid #000;padding:6px 12px;font-size:13px;font-family:'Times New Roman';background:#f9fafb;outline:none;box-sizing:border-box" placeholder="Cari...">
                            <ul class="select-options" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto">
                                @foreach($branches as $b)
                                    <li data-value="{{ $b->id }}" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:pointer" class="select-li {{ old('branch_id') == $b->id ? 's-selected' : '' }}">{{ $b->name }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <select name="branch_id" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
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
                    <div class="select-wrapper relative" style="position:relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between {{ $errors->has('project_name') ? 'border-[#e91d2a]' : 'border-black' }}" tabindex="0">
                            <span class="select-text">— Pilih Proyek —</span>
                            <span class="select-arrow">▼</span>
                        </div>
                        <div class="select-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;border:2px solid #000;background:#fff">
                            <input type="text" class="select-search" style="width:100%;border-bottom:2px solid #000;padding:6px 12px;font-size:13px;font-family:'Times New Roman';background:#f9fafb;outline:none;box-sizing:border-box" placeholder="Cari...">
                            <ul class="select-options" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto">
                                @foreach($projects as $p)
                                    <li data-value="{{ $p->project_name }}" data-branch="{{ $p->branch_id }}" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:pointer" class="select-li {{ old('project_name') === $p->project_name ? 's-selected' : '' }}">{{ $p->project_name }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <select name="project_name" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
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
                    <div class="select-wrapper relative" style="position:relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between {{ $errors->has('lead_source') ? 'border-[#e91d2a]' : 'border-black' }}" tabindex="0">
                            <span class="select-text">— Pilih Sumber —</span>
                            <span class="select-arrow">▼</span>
                        </div>
                        <div class="select-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;border:2px solid #000;background:#fff">
                            <input type="text" class="select-search" style="width:100%;border-bottom:2px solid #000;padding:6px 12px;font-size:13px;font-family:'Times New Roman';background:#f9fafb;outline:none;box-sizing:border-box" placeholder="Cari...">
                            <ul class="select-options" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto">
                                @foreach($sources as $src)
                                    <li data-value="{{ $src }}" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:pointer" class="select-li {{ old('lead_source') === $src ? 's-selected' : '' }}">{{ $src }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <select name="lead_source" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
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

            <style>
            .select-li:hover { background:#e6915d; color:#fff; }
            .select-li.s-selected { background:#e6915d; color:#fff; }
            </style>

            <script>
            var projectData = [
                @foreach($projects as $p)
                { name: {{ json_encode($p->project_name) }}, branch: '{{ $p->branch_id }}' },
                @endforeach
            ];

            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('.select-wrapper').forEach(function(wrapper) {
                    if (wrapper.__sw) return;
                    wrapper.__sw = true;

                    var display = wrapper.querySelector('.select-display');
                    var textEl = display ? display.querySelector('.select-text') : null;
                    var arrow = display ? display.querySelector('.select-arrow') : null;
                    var dropdown = wrapper.querySelector('.select-dropdown');
                    var search = dropdown ? dropdown.querySelector('.select-search') : null;
                    var list = dropdown ? dropdown.querySelector('.select-options') : null;
                    var select = wrapper.querySelector('select');

                    if (!display || !textEl || !arrow || !dropdown || !search || !list || !select) {
                        console.warn('CustomSelect init failed for', wrapper);
                        return;
                    }

                    function sync() {
                        var idx = select.selectedIndex;
                        textEl.textContent = idx > 0 ? select.options[idx].text : select.options[0].text;
                    }

                    display.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var isOpen = dropdown.style.display !== 'none';
                        if (isOpen) {
                            dropdown.style.display = 'none';
                            arrow.textContent = '\u25BC';
                        } else {
                            dropdown.style.display = 'block';
                            arrow.textContent = '\u25B2';
                            search.value = '';
                            search.focus();
                            Array.from(list.children).forEach(function(li) { li.style.display = ''; });
                        }
                    });

                    search.addEventListener('input', function() {
                        var q = this.value.toLowerCase();
                        Array.from(list.children).forEach(function(li) {
                            li.style.display = li.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
                        });
                    });

                    search.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            var visible = list.querySelector('li:not([style*="display: none"])');
                            if (visible && visible.getAttribute('data-value')) {
                                selectOption(visible);
                            }
                        }
                        if (e.key === 'Escape') {
                            dropdown.style.display = 'none';
                            arrow.textContent = '\u25BC';
                        }
                    });

                    list.addEventListener('click', function(e) {
                        var li = e.target.closest('li');
                        if (li) selectOption(li);
                    });

                    function selectOption(li) {
                        list.querySelectorAll('li').forEach(function(l) {
                            l.classList.remove('s-selected');
                        });
                        li.classList.add('s-selected');
                        textEl.textContent = li.textContent;
                        select.value = li.getAttribute('data-value');
                        var evt = new Event('change', { bubbles: true });
                        select.dispatchEvent(evt);
                        dropdown.style.display = 'none';
                        arrow.textContent = '\u25BC';
                    }

                    document.addEventListener('click', function(e) {
                        if (!wrapper.contains(e.target)) {
                            dropdown.style.display = 'none';
                            arrow.textContent = '\u25BC';
                        }
                    });

                    sync();

                    var sw = display.offsetWidth;
                    if (sw > 0) dropdown.style.width = sw + 'px';
                });

                var branchSelect = document.querySelector('[name="branch_id"]');
                if (branchSelect) {
                    branchSelect.addEventListener('change', function() {
                        var branchId = this.value;
                        var proyekSelect = document.querySelector('[name="project_name"]');
                        if (!proyekSelect) return;

                        var wrapper = proyekSelect.closest('.select-wrapper');
                        var proyekList = wrapper.querySelector('.select-options');
                        var proyekText = wrapper.querySelector('.select-text');
                        var currentVal = proyekSelect.value;

                        while (proyekSelect.options.length > 1) proyekSelect.remove(1);

                        proyekList.innerHTML = '';
                        var ph = document.createElement('li');
                        ph.setAttribute('data-value', '');
                        ph.textContent = '\u2014 Pilih Proyek \u2014';
                        ph.style.cssText = 'padding:6px 12px;font-size:13px;font-family:\'Times New Roman\';cursor:pointer';
                        ph.className = 'select-li';
                        proyekList.appendChild(ph);

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
                                li.setAttribute('data-value', projectData[i].name);
                                li.textContent = projectData[i].name;
                                li.style.cssText = 'padding:6px 12px;font-size:13px;font-family:\'Times New Roman\';cursor:pointer';
                                li.className = 'select-li';
                                if (projectData[i].name === currentVal) li.classList.add('s-selected');
                                proyekList.appendChild(li);
                            }
                        }

                        proyekText.textContent = hasMatch ? currentVal : '\u2014 Pilih Proyek \u2014';
                        if (!hasMatch) proyekSelect.value = '';
                    });

                    if (branchSelect.value) {
                        var evt = new Event('change', { bubbles: true });
                        branchSelect.dispatchEvent(evt);
                    }
                }
            });
            </script>
        </div>
    </div>
@endsection
