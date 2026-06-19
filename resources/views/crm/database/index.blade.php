@extends('layouts.crm')

@section('title', 'Database - Oasis CRM')

@section('content')
    <div class="bg-[#d77a7a] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Database</h1>
    </div>

    @if(Auth::user()->canViewAllBranches() && isset($branches) && $branches->count() > 0)
    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('database.index') }}" class="flex items-center gap-3 flex-wrap">
            <label class="font-[Helvetica] font-bold text-xs uppercase">Pilih Cabang:</label>
            <select name="branch_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Pilih Cabang —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ request('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }} ({{ $b->code }})</option>
                @endforeach
            </select>
        </form>
    </div>
    @elseif(isset($branchCode))
    <div class="bg-[#fcc20f] border-2 border-black px-4 py-2 mb-4">
        <span class="font-['Arial_Black'] font-black text-lg uppercase">Cabang: {{ $branchCode }}</span>
    </div>
    @endif

    @if(isset($error))
    <div class="bg-[#d77a7a] border-2 border-black px-4 py-3 mb-4 font-['Times_New_Roman'] text-sm">
        {{ $error }}
    </div>
    @endif

    @if(isset($branchCode) && !isset($error))
    <div class="border-2 border-black mb-4">
        <div class="bg-black text-white px-3 py-2 font-[Helvetica] font-bold text-xs uppercase">
            Database — {{ $branchCode }}
        </div>
        <div class="p-4">
            <div id="sheet-tabs" class="flex flex-wrap gap-1 mb-3"></div>

            <div class="mb-3">
                <input type="text" id="search-input" placeholder="Cari..."
                       class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white w-full max-w-xs">
            </div>

            <div id="table-container" class="overflow-x-auto border-2 border-black">
                <div class="text-center py-8 text-sm font-['Times_New_Roman']">Memuat data...</div>
            </div>

            @if($selectedBranch && $selectedBranch->sheet_id)
            <div class="mt-3">
                <a href="https://docs.google.com/spreadsheets/d/{{ $selectedBranch->sheet_id }}/edit" target="_blank"
                   class="inline-block bg-black text-white px-4 py-1.5 text-xs font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">
                    Buka Google Sheet
                </a>
            </div>
            @endif
        </div>
    </div>

    <script>
    var rawData = @json($data);
    var state = { sheet: null, rows: [] };

    function initData(data) {
        if (!data) {
            showNoData();
            return;
        }

        if (Array.isArray(data)) {
            if (data.length === 0) { showNoData(); return; }
            state.rows = data;
            renderTable(data);
            return;
        }

        if (typeof data === 'object') {
            var keys = Object.keys(data);
            var sheetKeys = keys.filter(function(k) { return Array.isArray(data[k]); });
            if (sheetKeys.length === 0) { renderObject(data); return; }
            renderSheetTabs(data, sheetKeys);
            return;
        }

        showInvalid();
    }

    function renderSheetTabs(data, keys) {
        var tabsEl = document.getElementById('sheet-tabs');
        tabsEl.innerHTML = '';
        keys.forEach(function(key, i) {
            var btn = document.createElement('button');
            btn.textContent = key;
            btn.className = 'px-3 py-1 text-xs font-[Helvetica] font-bold border border-black ' + (i === 0 ? 'bg-black text-white' : 'bg-white text-black hover:bg-gray-100');
            btn.setAttribute('data-sheet', key);
            btn.addEventListener('click', function() {
                tabsEl.querySelectorAll('button').forEach(function(b) {
                    b.className = 'px-3 py-1 text-xs font-[Helvetica] font-bold border border-black bg-white text-black hover:bg-gray-100';
                });
                btn.className = 'px-3 py-1 text-xs font-[Helvetica] font-bold border border-black bg-black text-white';
                state.rows = data[key] || [];
                renderTable(state.rows);
            });
            tabsEl.appendChild(btn);
        });
        state.rows = data[keys[0]] || [];
        renderTable(state.rows);
    }

    function renderObject(data) {
        var container = document.getElementById('table-container');
        var html = '<table class="w-full text-sm font-[\'Times_New_Roman\']"><tbody>';
        for (var key in data) {
            if (Object.prototype.hasOwnProperty.call(data, key)) {
                html += '<tr class="border-b border-black"><td class="px-3 py-2 font-bold">' + key + '</td><td class="px-3 py-2">' + (data[key] ?? '') + '</td></tr>';
            }
        }
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function renderTable(rows) {
        var container = document.getElementById('table-container');
        if (!rows || rows.length === 0) { showNoData(); return; }

        var headers = Object.keys(rows[0]);
        var q = (document.getElementById('search-input').value || '').toLowerCase();
        var filtered = q ? rows.filter(function(r) {
            for (var i = 0; i < headers.length; i++) {
                var val = String(r[headers[i]] || '').toLowerCase();
                if (val.indexOf(q) !== -1) return true;
            }
            return false;
        }) : rows;

        var html = '<table class="w-full text-sm font-[\'Times_New_Roman\'] border-collapse">';
        html += '<thead><tr class="bg-black text-white">';
        headers.forEach(function(h) {
            html += '<th class="px-3 py-2 text-left font-[Helvetica] font-bold text-xs uppercase">' + h + '</th>';
        });
        html += '</tr></thead><tbody>';
        filtered.forEach(function(row, i) {
            html += '<tr class="' + (i % 2 === 0 ? 'bg-white' : 'bg-gray-50') + ' border-b border-black hover:bg-[#fff3cd]">';
            headers.forEach(function(h) {
                html += '<td class="px-3 py-1.5">' + (row[h] ?? '') + '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table>';

        if (filtered.length === 0) {
            html = '<div class="text-center py-8 text-sm font-[\'Times_New_Roman\']">Tidak ada data yang cocok</div>';
        }

        container.innerHTML = html;
    }

    function showNoData() {
        document.getElementById('table-container').innerHTML = '<div class="text-center py-8 text-sm font-[\'Times_New_Roman\']">Tidak ada data.</div>';
    }

    function showInvalid() {
        document.getElementById('table-container').innerHTML = '<div class="text-center py-8 text-sm font-[\'Times_New_Roman\']">Format data tidak dikenali.</div>';
    }

    document.addEventListener('DOMContentLoaded', function() {
        initData(rawData);
        document.getElementById('search-input').addEventListener('input', function() {
            renderTable(state.rows);
        });
    });
    </script>
    @elseif(!isset($branchCode))
    <div class="border-2 border-black">
        <div class="bg-white px-6 py-8 text-center">
            <p class="font-['Times_New_Roman'] text-sm">
                @if(Auth::user()->canViewAllBranches())
                    Silakan pilih cabang terlebih dahulu untuk mengakses database.
                @else
                    Database branch belum tersedia. Hubungi superadmin.
                @endif
            </p>
        </div>
    </div>
    @endif
@endsection
