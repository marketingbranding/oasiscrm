@extends('layouts.crm')

@section('title', 'Edit Lead Harian - Oasis CRM')

@section('content')
    <div class="bg-[#e6915d] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Edit Lead Harian</h1>
    </div>

    <div class="border-2 border-black bg-white">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">
            Form Edit Harian
        </div>
        <div class="p-4 sm:p-6">
            <form method="POST" action="{{ route('lead-daily.update', ['lead_daily' => $daily->id]) }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Event</label>
                    <input type="text" value="{{ $daily->leadEvent->event_id ?? '#' . $daily->lead_event_id }} — {{ $daily->leadEvent->project_name }}" readonly
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-gray-100 rounded-none">
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tanggal</label>
                    <div class="date-wrapper" style="position:relative">
                        <div class="date-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black @error('date') border-[#e91d2a] @enderror" tabindex="0">
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
                        <input type="date" name="date" value="{{ old('date', $daily->date->format('Y-m-d')) }}"
                               style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                    </div>
                    @error('date') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Leads Didapat</label>
                    <input type="number" name="leads_count" value="{{ old('leads_count', $daily->leads_count) }}" min="0"
                           class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('leads_count') border-[#e91d2a] @enderror">
                    @error('leads_count') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4 pt-2 border-t-2 border-black">
                    <div>
                        <span class="font-[Helvetica] font-bold text-xs uppercase">Hari Ke</span>
                        <p class="text-sm font-['Times_New_Roman'] mt-1">{{ $daily->day_number ?? '—' }}</p>
                    </div>
                    <div>
                        <span class="font-[Helvetica] font-bold text-xs uppercase">Leads Kumulatif</span>
                        <p class="text-sm font-['Times_New_Roman'] mt-1">{{ $daily->cumulative_leads }}</p>
                    </div>
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

<style>
.cal-day { padding:5px 2px;text-align:center;cursor:pointer;border-bottom:1px solid #eee;border-right:1px solid #eee;font-family:'Times New Roman';font-size:13px;color:#000; }
.cal-day:nth-child(7n) { border-right:none; }
.cal-day:hover { background:#c0392b; color:#fff; }
.cal-day.cal-other { color:#ccc; cursor:default; }
.cal-day.cal-other:hover { background:transparent; color:#ccc; }
.cal-day.cal-today { font-weight:bold; text-decoration:underline; }
.cal-day.cal-selected { background:#c0392b; color:#fff; font-weight:bold; }
</style>

<script>
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

            <div class="border-t-2 border-black mt-6 pt-4">
                <form method="POST" action="{{ route('lead-daily.destroy', ['lead_daily' => $daily->id]) }}"
                      onsubmit="return confirm('Hapus data harian ini?')">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
                    <input type="hidden" name="lead_event_id" value="{{ request('lead_event_id') }}">
                    <input type="hidden" name="project_name" value="{{ request('project_name') }}">
                    <button type="submit" class="bg-[#e91d2a] text-white px-6 py-2 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-red-600">
                        Hapus Data Harian
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
