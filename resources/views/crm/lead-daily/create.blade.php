@extends('layouts.crm')

@section('title', 'Input Lead Harian - Oasis CRM')

@section('content')
    <x-crm.page-header color="#e6915d" title="Input Lead Harian" />

    <div class="border-2 border-black bg-white">
        <div class="bg-black text-white px-4 py-2 font-[Helvetica] font-bold text-xs uppercase">
            Form Input Harian
        </div>
        <div class="p-4 sm:p-6">
            <form method="POST" action="{{ route('lead-daily.store') }}" class="space-y-4">
                @csrf

                @if(Auth::user()->canViewAllBranches() && isset($branches) && $branches->count() > 0)
                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Cabang</label>
                    <select id="filter_branch" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                        <option value="">— Pilih Cabang —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Proyek</label>
                    <div class="select-wrapper" data-accent="#e6915d" style="position:relative">
                        <div class="select-display w-full border-2 px-3 py-2 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between border-black" tabindex="0">
                            <span class="select-text">— Pilih Proyek —</span>
                            <span class="select-arrow">▼</span>
                        </div>
                        <div class="select-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;border:2px solid #000;background:#fff">
                            <input type="text" class="select-search" style="width:100%;border-bottom:2px solid #000;padding:6px 12px;font-size:13px;font-family:'Times New Roman';background:#f9fafb;outline:none;box-sizing:border-box" placeholder="Cari...">
                            <ul class="select-options" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto">
                                @foreach($projects as $p)
                                    <li data-value="{{ $p->project_name }}" data-branch="{{ $p->branch_id }}" style="padding:6px 12px;font-size:13px;font-family:'Times New Roman';cursor:pointer" class="select-li">{{ $p->project_name }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <select id="filter_project" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                            <option value="">— Pilih Proyek —</option>
                            @foreach($projects as $p)
                                <option value="{{ $p->project_name }}" data-branch="{{ $p->branch_id }}">{{ $p->project_name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Event</label>
                    <select name="lead_event_id" id="filter_event" class="w-full border-2 border-black px-3 py-2 text-sm font-['Times_New_Roman'] bg-white rounded-none @error('lead_event_id') border-[#e91d2a] @enderror">
                        <option value="">— Pilih Event —</option>
                        @foreach($events as $e)
                            <option value="{{ $e->id }}" data-branch="{{ $e->branch_id }}" data-project="{{ $e->project_name }}" {{ old('lead_event_id') == $e->id ? 'selected' : '' }}>
                                {{ $e->event_id ?? '#' . $e->id }} — {{ $e->project_name }} ({{ $e->lead_source }}) — {{ $e->branch->name ?? '' }}
                            </option>
                        @endforeach
                    </select>
                    @error('lead_event_id') <p class="text-[#e91d2a] text-xs mt-1 font-[Helvetica] font-bold">{{ $message }}</p> @enderror
                </div>

                <input type="hidden" name="branch_id" id="hidden_branch_id" value="{{ old('branch_id') }}">

                <div>
                    <label class="font-[Helvetica] font-bold text-xs uppercase block mb-1">Tanggal</label>
                    <div class="date-wrapper" data-accent="#e6915d" style="position:relative">
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
                        <input type="date" name="date" value="{{ old('date', date('Y-m-d')) }}"
                               style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                    </div>
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

<script>
var projectData = [
    @foreach($projects as $p)
    { name: @json($p->project_name), branch: @json($p->branch_id) },
    @endforeach
];

var eventData = [
    @foreach($events as $e)
    { id: @json($e->id), project: @json($e->project_name), branch: @json($e->branch_id), display: @json(($e->event_id ?? '#' . $e->id) . ' — ' . $e->project_name . ' (' . $e->lead_source . ') — ' . ($e->branch->name ?? '')) },
    @endforeach
];

document.addEventListener('DOMContentLoaded', function() {
    function filterEvents() {
        var projectSelect = document.getElementById('filter_project');
        var eventSelect = document.getElementById('filter_event');
        var hiddenBranch = document.getElementById('hidden_branch_id');
        var selectedProject = projectSelect.value;
        var currentVal = eventSelect.value;

        while (eventSelect.options.length > 1) eventSelect.remove(1);
        hiddenBranch.value = '';

        var hasMatch = false;
        for (var i = 0; i < eventData.length; i++) {
            if (eventData[i].project === selectedProject) {
                var opt = document.createElement('option');
                opt.value = eventData[i].id;
                opt.textContent = eventData[i].display;
                opt.setAttribute('data-branch', eventData[i].branch);
                opt.setAttribute('data-project', eventData[i].project);
                if (eventData[i].id == currentVal) { opt.selected = true; hasMatch = true; }
                eventSelect.add(opt);
            }
        }
        if (hasMatch) {
            var selOpt = eventSelect.options[eventSelect.selectedIndex];
            if (selOpt) hiddenBranch.value = selOpt.getAttribute('data-branch') || '';
        }
    }

    function filterProjects() {
        var branchSelect = document.getElementById('filter_branch');
        var projectSelect = document.getElementById('filter_project');
        var projectWrapper = projectSelect ? projectSelect.closest('.select-wrapper') : null;
        if (!branchSelect || !projectSelect || !projectWrapper) return;

        var proyekList = projectWrapper.querySelector('.select-options');
        var proyekText = projectWrapper.querySelector('.select-text');
        var branchId = branchSelect.value;
        var currentVal = projectSelect.value;

        while (projectSelect.options.length > 1) projectSelect.remove(1);
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
                projectSelect.add(opt);

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
        if (!hasMatch) projectSelect.value = '';

        filterEvents();
    }

    var projectSelect = document.getElementById('filter_project');
    if (projectSelect) {
        projectSelect.addEventListener('change', filterEvents);
    }

    var branchSelect = document.getElementById('filter_branch');
    if (branchSelect) {
        branchSelect.addEventListener('change', filterProjects);
        if (branchSelect.value) {
            var evt = new Event('change', { bubbles: true });
            branchSelect.dispatchEvent(evt);
        }
    }

    var eventSelect = document.getElementById('filter_event');
    if (eventSelect) {
        eventSelect.addEventListener('change', function() {
            var hiddenBranch = document.getElementById('hidden_branch_id');
            if (this.selectedIndex > 0) {
                var selOpt = this.options[this.selectedIndex];
                hiddenBranch.value = selOpt.getAttribute('data-branch') || '';
            } else {
                hiddenBranch.value = '';
            }
        });
        if (eventSelect.selectedIndex > 0) {
            var evt = new Event('change', { bubbles: true });
            eventSelect.dispatchEvent(evt);
        }
    }
});
</script>
@endsection
