@extends('layouts.crm')

@section('title', 'Buat Dana Talangan - Oasis CRM')

@section('content')
    <div class="bg-[#f1c40f] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Buat Dana Talangan</h1>
    </div>

    <div class="border-2 border-black bg-white">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">
            Form Dana Talangan
        </div>
        <div class="p-4 sm:p-6">
            <form method="POST" action="{{ route('dana-talangan.store') }}" class="space-y-4">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tanggal</label>
                        <div class="date-wrapper" style="position:relative">
                            <div class="date-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black @error('tanggal') border-[#e91d2a] @enderror" tabindex="0">
                                <span class="date-text">— Pilih Tanggal —</span>
                                <span class="date-arrow">▼</span>
                            </div>
                            <div class="date-calendar" style="display:none;position:absolute;top:100%;left:0;z-index:9999;border:2px solid #000;background:#fff;width:280px">
                                <div class="cal-header" style="background:#000;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:6px 10px;font-family:'Times New Roman';font-size:14px;font-weight:bold;user-select:none">
                                    <button class="cal-prev" type="button" style="background:none;border:none;color:#fff;cursor:pointer;font-size:14px;padding:2px 8px;font-family:'Times New Roman';font-weight:bold;line-height:1">◀</button>
                                    <span class="cal-title">Bulan Tahun</span>
                                    <button class="cal-next" type="button" style="background:none;border:none;color:#fff;cursor:pointer;font-size:14px;padding:2px 8px;font-family:'Times New Roman';font-weight:bold;line-height:1">▶</button>
                                </div>
                                <div class="cal-weekdays" style="display:grid;grid-template-columns:repeat(7,1fr);border-bottom:2px solid #000;font-family:'Times New Roman';font-size:11px;font-weight:bold;text-align:center;background:#f5f5f5;color:#000">
                                    <span style="padding:5px 0;border-right:1px solid #ddd">Min</span>
                                    <span style="padding:5px 0;border-right:1px solid #ddd">Sen</span>
                                    <span style="padding:5px 0;border-right:1px solid #ddd">Sel</span>
                                    <span style="padding:5px 0;border-right:1px solid #ddd">Rab</span>
                                    <span style="padding:5px 0;border-right:1px solid #ddd">Kam</span>
                                    <span style="padding:5px 0;border-right:1px solid #ddd">Jum</span>
                                    <span style="padding:5px 0">Sab</span>
                                </div>
                                <div class="cal-grid" style="display:grid;grid-template-columns:repeat(7,1fr);font-family:'Times New Roman';font-size:13px"></div>
                            </div>
                            <input type="date" name="tanggal" value="{{ old('tanggal', date('Y-m-d')) }}" required
                                   style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                        </div>
                        @error('tanggal') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Nama Konsumen</label>
                        <input type="text" name="nama_konsumen" value="{{ old('nama_konsumen') }}" required
                               class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('nama_konsumen') border-[#e91d2a] @enderror">
                        @error('nama_konsumen') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>
                </div>

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
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black" tabindex="0">
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

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Kav</label>
                        <input type="text" name="kav" value="{{ old('kav') }}"
                               class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('kav') border-[#e91d2a] @enderror">
                        @error('kav') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Pinjam Nama</label>
                        <select name="pinjam_nama" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                            <option value="0" {{ old('pinjam_nama') ? '' : 'selected' }}>TIDAK</option>
                            <option value="1" {{ old('pinjam_nama') ? 'selected' : '' }}>YA</option>
                        </select>
                    </div>

                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Umur</label>
                        <input type="number" name="umur" value="{{ old('umur') }}" min="0" max="150"
                               class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('umur') border-[#e91d2a] @enderror">
                        @error('umur') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Pekerjaan</label>
                        <input type="text" name="pekerjaan" value="{{ old('pekerjaan') }}"
                               class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('pekerjaan') border-[#e91d2a] @enderror">
                        @error('pekerjaan') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Status Perkawinan</label>
                        <input type="text" name="status_perkawinan" value="{{ old('status_perkawinan') }}" placeholder="Cth: KAWIN (ANAK 2)"
                               class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('status_perkawinan') border-[#e91d2a] @enderror">
                        @error('status_perkawinan') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Nama Marketing</label>
                    <input type="text" name="nama_marketing" value="{{ old('nama_marketing') }}"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('nama_marketing') border-[#e91d2a] @enderror">
                    @error('nama_marketing') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Penyelesaian</label>
                    <textarea name="penyelesaian" rows="3" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('penyelesaian') border-[#e91d2a] @enderror">{{ old('penyelesaian') }}</textarea>
                    @error('penyelesaian') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="bg-black text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                        Simpan
                    </button>
                    <a href="{{ route('dana-talangan.index', array_filter(request()->only(['branch_id', 'project_name', 'status']))) }}" class="bg-white text-black px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                        Batal
                    </a>
                </div>
            </form>
        </div>
    </div>

<style>
.select-li:hover { background:#f1c40f; color:#fff; }
.select-li.s-selected { background:#f1c40f; color:#fff; }
.cal-day { padding:5px 2px;text-align:center;cursor:pointer;border-bottom:1px solid #eee;border-right:1px solid #eee;font-family:'Times New Roman';font-size:13px;color:#000; }
.cal-day:nth-child(7n) { border-right:none; }
.cal-day:hover { background:#f1c40f; color:#fff; }
.cal-day.cal-other { color:#ccc; cursor:default; }
.cal-day.cal-other:hover { background:transparent; color:#ccc; }
.cal-day.cal-today { font-weight:bold; text-decoration:underline; }
.cal-day.cal-selected { background:#f1c40f; color:#fff; font-weight:bold; }
</style>

<script>
var projectData = [
    @foreach($projects as $p)
    { name: @json($p->project_name), branch: @json($p->branch_id) },
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
        if (!display || !textEl || !arrow || !dropdown || !search || !list || !select) return;

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
            list.querySelectorAll('li').forEach(function(l) { l.classList.remove('s-selected'); });
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
                if (!branchId || !projectData[i].branch || projectData[i].branch == branchId) {
                    var opt = document.createElement('option');
                    opt.value = projectData[i].name;
                    opt.textContent = projectData[i].name;
                    if (projectData[i].name === currentVal) { opt.selected = true; hasMatch = true; }
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

// --- Date Calendar ---
var monthsId = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

function getCalState(wrapper) {
    var input = wrapper.querySelector('input[type="date"]');
    if (input && input.value) {
        var p = input.value.split('-');
        return { year: parseInt(p[0], 10), month: parseInt(p[1], 10) - 1 };
    }
    return null;
}

function renderCalendar(wrapper) {
    if (!wrapper.__calState)
        wrapper.__calState = getCalState(wrapper) || { year: new Date().getFullYear(), month: new Date().getMonth() };
    var state = wrapper.__calState;
    var y = state.year, m = state.month;
    var first = new Date(y, m, 1).getDay();
    var days = new Date(y, m + 1, 0).getDate();
    var prevDays = new Date(y, m, 0).getDate();
    var grid = wrapper.querySelector('.cal-grid');
    var title = wrapper.querySelector('.cal-title');
    if (!grid || !title) return;
    grid.innerHTML = '';
    for (var i = 0; i < 42; i++) {
        var div = document.createElement('div');
        div.className = 'cal-day';
        if (i < first) {
            div.textContent = prevDays - first + i + 1;
            div.classList.add('cal-other');
        } else if (i >= first + days) {
            div.textContent = i - first - days + 1;
            div.classList.add('cal-other');
        } else {
            var dayNum = i - first + 1;
            var ds = y + '-' + String(m + 1).padStart(2, '0') + '-' + String(dayNum).padStart(2, '0');
            div.textContent = dayNum;
            div.setAttribute('data-date', ds);
            var input = wrapper.querySelector('input[type="date"]');
            if (input && input.value === ds) div.classList.add('cal-selected');
            var today = new Date();
            var todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
            if (ds === todayStr) div.classList.add('cal-today');
            (function(d) { div.addEventListener('click', function() { selectDate(wrapper, d); }); })(ds);
        }
        grid.appendChild(div);
    }
    title.textContent = monthsId[m] + ' ' + y;
}

function selectDate(wrapper, dateStr) {
    var input = wrapper.querySelector('input[type="date"]');
    if (!input) return;
    input.value = dateStr;
    var parts = dateStr.split('-');
    var d = parseInt(parts[2], 10);
    var m = parseInt(parts[1], 10) - 1;
    var y = parseInt(parts[0], 10);
    var textEl = wrapper.querySelector('.date-text');
    if (textEl) textEl.textContent = d + ' ' + monthsId[m] + ' ' + y;
    var cal = wrapper.querySelector('.date-calendar');
    if (cal) cal.style.display = 'none';
    var arrow = wrapper.querySelector('.date-arrow');
    if (arrow) arrow.textContent = '\u25BC';
    var evt = document.createEvent('HTMLEvents');
    evt.initEvent('change', true, false);
    input.dispatchEvent(evt);
    wrapper.__calState = { year: y, month: m };
    renderCalendar(wrapper);
}

function syncDateDisplay(wrapper) {
    var input = wrapper.querySelector('input[type="date"]');
    var textEl = wrapper.querySelector('.date-text');
    if (input && textEl && input.value) {
        var parts = input.value.split('-');
        var d = parseInt(parts[2], 10);
        var m = parseInt(parts[1], 10) - 1;
        var y = parseInt(parts[0], 10);
        textEl.textContent = d + ' ' + monthsId[m] + ' ' + y;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.date-wrapper').forEach(function(wrapper) {
        if (wrapper.__dw) return;
        wrapper.__dw = true;
        var display = wrapper.querySelector('.date-display');
        var calendar = wrapper.querySelector('.date-calendar');
        var arrow = wrapper.querySelector('.date-arrow');
        if (!display || !calendar) return;
        syncDateDisplay(wrapper);
        display.addEventListener('click', function(e) {
            e.stopPropagation();
            var isOpen = calendar.style.display !== 'none';
            if (isOpen) {
                calendar.style.display = 'none';
                if (arrow) arrow.textContent = '\u25BC';
            } else {
                calendar.style.display = 'block';
                if (arrow) arrow.textContent = '\u25B2';
                renderCalendar(wrapper);
            }
        });
        document.addEventListener('click', function(e) {
            if (!wrapper.contains(e.target)) {
                calendar.style.display = 'none';
                if (arrow) arrow.textContent = '\u25BC';
            }
        });
        var prev = calendar.querySelector('.cal-prev');
        var next = calendar.querySelector('.cal-next');
        if (prev) prev.addEventListener('click', function(e) {
            e.stopPropagation();
            if (!wrapper.__calState) wrapper.__calState = { year: new Date().getFullYear(), month: new Date().getMonth() };
            wrapper.__calState.month--;
            if (wrapper.__calState.month < 0) { wrapper.__calState.month = 11; wrapper.__calState.year--; }
            renderCalendar(wrapper);
        });
        if (next) next.addEventListener('click', function(e) {
            e.stopPropagation();
            if (!wrapper.__calState) wrapper.__calState = { year: new Date().getFullYear(), month: new Date().getMonth() };
            wrapper.__calState.month++;
            if (wrapper.__calState.month > 11) { wrapper.__calState.month = 0; wrapper.__calState.year++; }
            renderCalendar(wrapper);
        });
    });
});
</script>
@endsection
