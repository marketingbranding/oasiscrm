@extends('layouts.crm')

@section('title', 'Database - Oasis CRM')

@section('content')
    <div class="bg-[#d77a7a] border-2 border-black px-4 py-2 mb-6">
        <h1 class="font-['Arial_Black'] font-black text-xl uppercase">Database</h1>
    </div>

    @if(Auth::user()->isSuperadmin() && isset($branches) && $branches->count() > 0)
    <div class="bg-white border-2 border-black p-3 mb-6">
        <form method="GET" action="{{ route('database.index') }}" class="flex items-center gap-3 flex-wrap">
            <label class="font-[Helvetica] font-bold text-xs uppercase">Pilih Cabang:</label>
            <select name="branch_id" onchange="this.form.submit()" class="border-2 border-black px-3 py-1.5 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                <option value="">— Pilih Cabang —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }} ({{ $b->code }})</option>
                @endforeach
            </select>
            <div class="h-6 w-px bg-black mx-1 hidden sm:block"></div>
            <div class="flex items-center gap-2 ml-auto">
                <button type="submit" form="database-sync-form" class="bg-white text-black px-3 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">
                    Sync Sekarang
                </button>
            </div>
        </form>
    </div>
    @elseif($selectedBranch)
    <div class="bg-[#fcc20f] border-2 border-black px-4 py-2 mb-4 flex items-center gap-3">
        <span class="font-['Arial_Black'] font-black text-lg uppercase">Cabang: {{ $selectedBranch->code }}</span>
        <button type="submit" form="database-sync-form" class="bg-white text-black px-3 py-1 text-xs font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100 ml-auto">
            Sync Sekarang
        </button>
    </div>
    @endif

    @if($selectedBranch)
    <form id="database-sync-form" method="POST" action="{{ route('database.sync') }}" class="hidden">
        @csrf
        <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">
    </form>

    <div class="border-2 border-black bg-white px-4 py-3 mb-4 font-['Times_New_Roman'] text-sm flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4">
        <div>
            <strong class="font-bold">Status Sync:</strong>
            @if($syncStatus?->status === 'success')
                <span>Terakhir sync {{ $syncStatus->finished_at?->format('d M Y H:i') }}</span>
            @elseif($syncStatus?->status === 'failed')
                <span class="text-[#c0392b]">Sync terakhir gagal: {{ $syncStatus->message }}</span>
            @elseif($syncStatus?->status === 'running')
                <span>Sedang sync...</span>
            @else
                <span>Belum pernah sync. Klik Sync Sekarang.</span>
            @endif
        </div>
        @if($isStale)
            <span class="inline-block bg-[#fcc20f] border-2 border-black px-2 py-1 font-[Helvetica] text-[10px] font-bold uppercase">Data perlu diperbarui</span>
        @endif
    </div>
    @endif

    @if($selectedBranch && !empty($sheetNames))
    @php
        $firstSheet = $sheetNames[0] ?? '';
        $initialRows = $records[$firstSheet] ?? [];
        $initialSample = $initialRows[0] ?? null;
        $initialHeaders = $initialSample ? $initialSample->headers : [];
        $initialFormulaCols = $initialSample ? ($initialSample->formula_columns ?? []) : [];
        $initialRecordsJson = json_encode(array_map(fn($r) => [
            'id' => $r->id,
            'row_number' => $r->row_number,
            'row_data' => $r->row_data,
        ], $initialRows));
        $initialHeadersJson = json_encode($initialHeaders);
        $initialFormulaJson = json_encode($initialFormulaCols);
    @endphp
    <div x-data="databaseTabs({
        branchId: '{{ $selectedBranch->id }}',
        editBaseUrl: '{{ url('database/records') }}',
        firstSheet: '{{ $firstSheet }}',
        initialHeaders: {{ $initialHeadersJson }},
        initialFormulaCols: {{ $initialFormulaJson }},
        initialRecords: {{ $initialRecordsJson }}
    })">
        <style>
            [x-cloak] { display: none !important; }
            .tab-wrap { overflow-x:auto; overflow-y:hidden; white-space:nowrap; max-width:100%; border-bottom:2px solid #000; margin-bottom:12px; scroll-behavior:smooth; }
            .tab-wrap::-webkit-scrollbar { height:4px; }
            .tab-wrap::-webkit-scrollbar-thumb { background:#d77a7a; border-radius:2px; }
            .tab-btn { display:inline-block; padding:6px 14px; border:2px solid #000; border-bottom:none;
                        font-family:Helvetica,sans-serif; font-weight:700; font-size:11px;
                        text-transform:uppercase; cursor:pointer; background:#fff; color:#000;
                        white-space:nowrap; margin:0; }
            .tab-btn + .tab-btn { border-left:none; }
            .tab-btn.active { background:#d77a7a; color:#fff; position:relative; }
            .tab-btn.active::after { content:''; position:absolute; bottom:-2px; left:0; right:0; height:2px; background:#d77a7a; z-index:11; }
            .tab-btn:hover:not(.active) { background:#f5f5f5; }
            .tab-btn.loading { opacity:0.6; cursor:wait; }
            .db-table { font-size:12px; border-collapse:collapse; width:100%; }
            .db-table th { position:sticky; top:0; z-index:5;
                        border:2px solid #000; background:#000; color:#fff;
                        font-family:Helvetica,sans-serif; font-weight:700; font-size:10px;
                        text-transform:uppercase; padding:6px 8px; white-space:nowrap; text-align:left; }
            .db-table td { border:2px solid #000; padding:4px 8px;
                        font-family:'Times New Roman',serif; }
            .db-table tbody tr:nth-child(even) { background:#f9fafb; }
            .db-table tbody tr:hover { background:#fef3c7; }
            .db-table th.row-num, .db-table td.row-num {
                width:44px; min-width:44px; max-width:44px;
            }
            .db-table.frozen th.row-num, .db-table.frozen td.row-num {
                position:sticky; left:0; z-index:10;
            }
            .db-table.frozen thead th.row-num { z-index:13; }
            .db-table.frozen tbody td.row-num { z-index:10; background:#fff !important; }
            .db-table.frozen tbody tr:nth-child(even) td.row-num { background:#f9fafb !important; }
            .db-table.frozen tbody tr:hover td.row-num { background:#fef3c7 !important; }

            .db-table.frozen th.col-id_kavling, .db-table.frozen td.col-id_kavling {
                position:sticky; left:44px; z-index:9;
                box-shadow:3px 0 0 0 #000;
            }
            .db-table.frozen thead th.col-id_kavling { z-index:12; }


        </style>

        <div class="tab-wrap" x-on:wheel="if ($event.currentTarget.scrollWidth > $event.currentTarget.clientWidth) { (function(e){e._sd=(e._sd||0)+$event.deltaY;if(!e._st){e._st=true;requestAnimationFrame(function(){var d=e._sd;e._sd=0;e._st=false;e.scrollLeft+=Math.sign(d)*Math.min(Math.abs(d)*1.5,160)})}}($event.currentTarget)); $event.preventDefault(); }">
            @foreach($sheetNames as $name)
            <button @click="switchTab('{{ $name }}')"
                    :class="{ 'active': tab === '{{ $name }}', 'loading': loading && tab === '{{ $name }}' }"
                    class="tab-btn">
                {{ $name }} <span :class="tab === '{{ $name }}' ? 'text-white/70' : 'text-gray-500'" style="font-size:9px;font-weight:400;">(<span x-text="sheetCount('{{ $name }}')">0</span>)</span>
            </button>
            @endforeach
        </div>

        {{-- Tab content -- rendered per sheet name --}}
        <template x-for="name in sheetNameList" :key="name">
            <div x-show="tab === name" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
                <div class="mb-3 flex items-center gap-2 flex-wrap" style="min-height:32px;">
                    <template x-if="currentData(name) && currentData(name).records.length > 0">
                        <button @click="adding = name"
                                class="bg-black text-white px-3 py-1 text-xs font-[Helvetica] font-bold border-2 border-black hover:bg-gray-800" style="border-radius:0;">
                            + Tambah Data
                        </button>
                    </template>
                    <a href="https://docs.google.com/spreadsheets/d/{{ $selectedBranch->sheet_id }}" target="_blank"
                       class="bg-white text-black px-3 py-1 text-xs font-[Helvetica] font-bold border-2 border-black hover:bg-gray-100" style="border-radius:0;">
                        Buka Google Sheet
                    </a>
                    <template x-if="isLoaded(name)">
                        <input type="text" x-model="filterText" placeholder="Cari..."
                               class="border-2 border-black px-2 py-1 text-xs font-['Times_New_Roman'] rounded-none w-32 sm:w-48 ml-auto">
                    </template>
                </div>

                <div x-show="!isLoaded(name)" class="border-2 border-black bg-white px-4 py-8 text-center">
                    <p class="text-sm font-['Times_New_Roman'] italic" style="color:#6b7280;" x-text="loading ? 'Memuat data...' : 'Klik tab untuk memuat data.'"></p>
                </div>

                <template x-if="isLoaded(name) && currentData(name).records.length > 0">
                    <div class="overflow-auto border-2 border-black" style="max-height:65vh;">
                        <table class="db-table" :class="{ frozen: frozen }">
                            <thead>
                                <tr>
                                    <th :class="{ 'row-num': frozen }" style="width:44px;text-align:center;">#</th>
                                    <template x-for="h in currentData(name).headers" :key="h">
                                        <th x-show="!['oasis_sync_id','oasis_deleted_at','oasis_deleted_by'].includes(h)"
                                            :class="(currentData(name).formula_columns.includes(h) ? 'formula-col ' : '') + (isIdKavlingColumn(h) ? 'col-id_kavling' : '')"
                                            @click="sortBy(h)"
                                            :style="'min-width:120px;cursor:pointer;user-select:none;' + (sortColumn === h ? 'background:#5b7db9;' : '')">
                                            <span x-text="h + sortIcon(h)"></span>
                                            <span x-show="isIdKavlingColumn(h)"
                                                  @click.stop="frozen = !frozen"
                                                  style="cursor:pointer;margin-left:4px;font-size:10px;font-weight:400;"
                                                  x-text="frozen ? '🔒' : '🔓'">
                                            </span>
                                            <template x-if="currentData(name).formula_columns.includes(h)">
                                                <span style="font-size:9px;font-weight:400;color:#fcc20f;">[f]</span>
                                            </template>
                                        </th>
                                    </template>
                                    <th style="width:100px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="rec in sortedRecords(name)" :key="rec.id">
                                    <tr>
                                        <td :class="{ 'row-num': frozen }" style="text-align:center;color:#6b7280;" x-text="rec.row_number"></td>
                                        <template x-for="h in currentData(name).headers" :key="h">
                                            <td x-show="!['oasis_sync_id','oasis_deleted_at','oasis_deleted_by'].includes(h)"
                                                :class="isIdKavlingColumn(h) ? 'col-id_kavling' : ''"
                                                :style="'background:' + (currentData(name).formula_columns.includes(h) ? '#b6d7a8' : (isIdKavlingColumn(h) && frozen ? '#fff' : 'transparent')) + ';color:#000;font-style:' + (currentData(name).formula_columns.includes(h) ? 'italic' : 'normal') + ';font-style:' + (currentData(name).formula_columns.includes(h) ? 'italic' : 'normal') + ';max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'"
                                                :title="rec.row_data[h] || ''">
                                                 <template x-if="['true', 'false', '1', '0'].includes((rec.row_data[h] || '').toLowerCase())">
                                                     <span class="inline-flex items-center justify-center w-5 h-5 border-2 border-black text-[10px] font-bold leading-none"
                                                           :class="['true', '1'].includes((rec.row_data[h] || '').toLowerCase()) ? 'bg-[#22c55e] text-white' : 'bg-white text-transparent'"
                                                           x-text="['true', '1'].includes((rec.row_data[h] || '').toLowerCase()) ? '✓' : ''">
                                                     </span>
                                                 </template>
                                                 <template x-if="!['true', 'false', '1', '0'].includes((rec.row_data[h] || '').toLowerCase())">
                                                    <span x-text="rec.row_data[h] || ''"></span>
                                                </template>
                                            </td>
                                        </template>
                                        <td style="white-space:nowrap;">
                                            <button @click="editRecord(rec)"
                                                    class="font-[Helvetica] font-bold underline" style="font-size:11px;color:#0000ee;margin-right:8px;">Edit</button>
                                            <form method="POST" :action="editBaseUrl + '/' + rec.id" style="display:inline;" @submit.prevent="confirm('Hapus data ini?') && $el.submit()">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="font-[Helvetica] font-bold underline" style="font-size:11px;color:#c0392b;">Hapus</button>
                                            </form>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </template>

                <template x-if="isLoaded(name) && currentData(name).records.length === 0">
                    <div class="border-2 border-black bg-white px-4 py-8 text-center">
                        <p class="text-sm font-['Times_New_Roman'] italic" style="color:#9ca3af;">—</p>
                    </div>
                </template>

                <template x-if="isLoaded(name) && currentData(name).records.length > 0 && sortedRecords(name).length === 0">
                    <div class="border-2 border-black bg-white px-4 py-8 text-center">
                        <p class="text-sm font-['Times_New_Roman'] italic" style="color:#9ca3af;">Tidak ada hasil yang cocok.</p>
                    </div>
                </template>
            </div>
        </template>

        {{-- Edit Modal --}}
        <div x-cloak x-show="editing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4"
             @keydown.escape.window="editing = null">
            <div @click.away="editing = null"
                 class="w-full max-w-2xl border-2 border-black bg-white p-5 shadow-[8px_8px_0_0_#000] max-h-[80vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-[Helvetica] font-bold text-sm uppercase" x-text="'Edit — ' + tab"></h2>
                    <button @click="editing = null" class="text-black font-bold text-lg leading-none">&times;</button>
                </div>
                <form method="POST" :action="editBaseUrl + '/' + editing.id">
                    @csrf @method('PUT')
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <template x-for="h in editableHeaders()" :key="h">
                            <div>
                                <label class="font-[Helvetica] font-bold text-[10px] uppercase block mb-0.5" x-text="h"></label>
                                 <template x-if="['true', 'false', '1', '0'].includes((editForm[h] || '').toLowerCase())">
                                     <label class="flex items-center gap-2 cursor-pointer">
                                         <input type="checkbox"
                                                :checked="['true', '1'].includes((editForm[h] || '').toLowerCase())"
                                                @change="editForm[h] = $event.target.checked ? 'true' : 'false'"
                                                class="w-5 h-5 accent-[#5d8e8e] border-2 border-black cursor-pointer rounded-none">
                                         <span class="text-xs font-['Times_New_Roman']" x-text="['true', '1'].includes((editForm[h] || '').toLowerCase()) ? 'Aktif' : 'Tidak'"></span>
                                     </label>
                                 </template>
                                 <template x-if="!['true', 'false', '1', '0'].includes((editForm[h] || '').toLowerCase())">
                                    <input :name="h" x-model="editForm[h]"
                                           class="w-full border-2 border-black px-2 py-1 text-sm font-['Times_New_Roman'] rounded-none">
                                </template>
                            </div>
                        </template>
                    </div>
                    <div class="flex items-center gap-2 mt-4">
                        <button type="submit" class="bg-black text-white px-6 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">Simpan</button>
                        <button type="button" @click="editing = null" class="bg-white text-black px-6 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">Batal</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Add Modal --}}
        <div x-cloak x-show="adding" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4"
             @keydown.escape.window="adding = null">
            <template x-for="name in sheetNameList" :key="name">
                <div x-show="adding === name" x-cloak
                     @click.away="adding = null"
                     class="w-full max-w-2xl border-2 border-black bg-white p-5 shadow-[8px_8px_0_0_#000] max-h-[80vh] overflow-y-auto">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="font-[Helvetica] font-bold text-sm uppercase" x-text="'Tambah Data — ' + name"></h2>
                        <button @click="adding = null" class="text-black font-bold text-lg leading-none">&times;</button>
                    </div>
                    <form method="POST" :action="'{{ url('database/records') }}'">
                        @csrf
                        <input type="hidden" name="sheet_name" :value="name">
                        <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <template x-for="h in addHeaders(name)" :key="h">
                                <div>
                                    <label class="font-[Helvetica] font-bold text-[10px] uppercase block mb-0.5" x-text="h"></label>
                                     <template x-if="isBooleanColumn(name, h)">
                                         <label class="flex items-center gap-2 cursor-pointer">
                                             <input type="checkbox" :name="h" value="true"
                                                    @change="$event.target.nextElementSibling.textContent = $event.target.checked ? 'Aktif' : 'Tidak'"
                                                    class="w-5 h-5 accent-[#5d8e8e] border-2 border-black cursor-pointer rounded-none">
                                             <span class="text-xs font-['Times_New_Roman']">Tidak</span>
                                         </label>
                                     </template>
                                     <template x-if="!isBooleanColumn(name, h)">
                                        <input :name="h" value=""
                                               class="w-full border-2 border-black px-2 py-1 text-sm font-['Times_New_Roman'] rounded-none">
                                    </template>
                                </div>
                            </template>
                        </div>
                        <div class="flex items-center gap-2 mt-4">
                            <button type="submit" class="bg-black text-white px-6 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-800">Simpan</button>
                            <button type="button" @click="adding = null" class="bg-white text-black px-6 py-1.5 text-sm font-[Helvetica] font-bold border-2 border-black rounded-none hover:bg-gray-100">Batal</button>
                        </div>
                    </form>
                </div>
            </template>
        </div>
    </div>
    @elseif($selectedBranch)
    <div class="border-2 border-black bg-white px-6 py-8 text-center">
        <p class="font-['Times_New_Roman'] text-sm">
            @if(Auth::user()->isSuperadmin())
                Belum ada data. Klik Sync Sekarang untuk memuat data.
            @else
                Database branch belum tersedia. Hubungi superadmin.
            @endif
        </p>
    </div>
    @elseif(!$selectedBranch)
    <div class="border-2 border-black bg-white px-6 py-8 text-center">
        <p class="font-['Times_New_Roman'] text-sm">
            @if(Auth::user()->isSuperadmin())
                Silakan pilih cabang terlebih dahulu.
            @else
                Database branch belum tersedia. Hubungi superadmin.
            @endif
        </p>
    </div>
    @endif
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('databaseTabs', (config) => ({
        tab: config.firstSheet,
        branchId: config.branchId,
        editBaseUrl: config.editBaseUrl,
        loading: false,
        editing: null,
        editForm: {},
        adding: null,
        cache: {},
        loaded: {},
        sortColumn: null,
        sortDirection: 'asc',
        filterText: '',
        frozen: true,

        init() {
            this.cache[config.firstSheet] = {
                headers: config.initialHeaders,
                formula_columns: config.initialFormulaCols,
                records: config.initialRecords,
            };
            this.loaded[config.firstSheet] = true;
            this.sheetNameList = @json($sheetNames);
        },

        currentData(name) {
            return this.cache[name] || null;
        },

        isLoaded(name) {
            return !!this.loaded[name];
        },

        sheetCount(name) {
            const s = this.cache[name];
            return s ? (s.records ? s.records.length : 0) : 0;
        },

        async switchTab(name) {
            this.tab = name;
            if (this.loaded[name]) return;
            this.loading = true;
            try {
                const res = await fetch(`/database/sheet/${this.branchId}/${encodeURIComponent(name)}`);
                const data = await res.json();
                this.cache[name] = data;
                this.loaded[name] = true;
            } catch (e) {
                this.cache[name] = { headers: [], formula_columns: [], records: [] };
                this.loaded[name] = true;
            } finally {
                this.loading = false;
            }
        },

        editRecord(rec) {
            this.editing = rec;
            this.editForm = JSON.parse(JSON.stringify(rec.row_data));
        },

        editableHeaders() {
            const sheet = this.cache[this.tab];
            if (!sheet) return [];
            return (sheet.headers || []).filter(h =>
                !sheet.formula_columns.includes(h) &&
                !['oasis_sync_id', 'oasis_deleted_at', 'oasis_deleted_by'].includes(h)
            );
        },

        addHeaders(name) {
            const sheet = this.cache[name];
            if (!sheet) return [];
            return (sheet.headers || []).filter(h =>
                !sheet.formula_columns.includes(h) &&
                !['oasis_sync_id', 'oasis_deleted_at', 'oasis_deleted_by'].includes(h)
            );
        },

        sortedRecords(name) {
            const data = this.cache[name];
            if (!data) return [];
            let records = [...data.records];

            if (this.filterText) {
                const ft = this.filterText.toLowerCase();
                const visibleHeaders = data.headers.filter(h =>
                    !['oasis_sync_id', 'oasis_deleted_at', 'oasis_deleted_by'].includes(h)
                );
                records = records.filter(r =>
                    visibleHeaders.some(h => {
                        const val = r.row_data[h];
                        return val && String(val).toLowerCase().includes(ft);
                    })
                );
            }

            if (this.sortColumn) {
                records.sort((a, b) => {
                    const rawA = a.row_data[this.sortColumn];
                    const rawB = b.row_data[this.sortColumn];
                    const numA = parseFloat(rawA);
                    const numB = parseFloat(rawB);
                    if (!isNaN(numA) && !isNaN(numB)) {
                        return this.sortDirection === 'asc' ? numA - numB : numB - numA;
                    }
                    const va = (rawA || '').toLowerCase();
                    const vb = (rawB || '').toLowerCase();
                    if (va < vb) return this.sortDirection === 'asc' ? -1 : 1;
                    if (va > vb) return this.sortDirection === 'asc' ? 1 : -1;
                    return 0;
                });
            }

            return records;
        },

        sortBy(header) {
            if (this.sortColumn === header) {
                if (this.sortDirection === 'asc') {
                    this.sortDirection = 'desc';
                } else {
                    this.sortColumn = null;
                    this.sortDirection = 'asc';
                }
            } else {
                this.sortColumn = header;
                this.sortDirection = 'asc';
            }
        },

        sortIcon(header) {
            if (this.sortColumn !== header) return '';
            return this.sortDirection === 'asc' ? ' ▼' : ' ▲';
        },

        isBooleanColumn(sheetName, header) {
            const data = this.cache[sheetName];
            if (!data || !data.records) return false;
            return data.records.some(r =>
                ['true', 'false', '1', '0'].includes((r.row_data[header] || '').toLowerCase())
            );
        },

        isIdKavlingColumn(h) {
            if (!h) return false;
            const lower = h.toLowerCase().replace(/[\s_-]/g, '');
            return lower === 'idkavling';
        },
    }));
});
</script>
@endpush