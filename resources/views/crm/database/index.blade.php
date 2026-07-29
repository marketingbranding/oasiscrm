@extends('layouts.crm')

@section('title', 'Database - Oasis CRM')

@section('content')
    <x-crm.page-header
        variant="canonical"
        eyebrow="Sales / Database"
        title="Database"
        description="Temukan dan kelola data konsumen dari cache Google Sheet pada ruang kerja yang sedang aktif."
        class="database-page-header"
    >
        <x-slot:actions>
            @if($selectedBranch)
                <x-crm.status-badge variant="info">Cabang: {{ $selectedBranch->name }}</x-crm.status-badge>
                <x-crm.status-badge variant="neutral">{{ count($sheetNames) }} sheet</x-crm.status-badge>
            @else
                <x-crm.status-badge variant="warning">Cabang belum dipilih</x-crm.status-badge>
            @endif
        </x-slot:actions>
    </x-crm.page-header>

    <x-crm.page-presence page-key="database" :branch-id="$selectedBranchId" />

    <x-crm.toolbar label="Ruang kerja Database" class="database-scope-toolbar">
        @if(isset($branches) && $branches->count() > 1)
            <form method="GET" action="{{ route('database.index') }}" class="database-branch-form">
                <label for="database-branch" class="crm-type-label">Pilih Cabang</label>
                <select id="database-branch" name="branch_id" onchange="this.form.submit()" class="crm-control database-branch-select">
                    <option value="">— Pilih Cabang —</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }} ({{ $b->code }})</option>
                    @endforeach
                </select>
                <noscript><x-crm.button type="submit" size="sm">Terapkan</x-crm.button></noscript>
            </form>
        @elseif($selectedBranch)
            <div class="database-scope-summary">
                <span class="crm-type-label">Ruang kerja aktif</span>
                <strong>Cabang: {{ $selectedBranch->name }} ({{ $selectedBranch->code }})</strong>
            </div>
        @else
            <div class="database-scope-summary">
                <span class="crm-type-label">Ruang kerja aktif</span>
                <strong>Cabang belum tersedia</strong>
            </div>
        @endif

        @if($selectedBranch)
            <x-slot:actions>
                <x-crm.sync-control module-key="database" module-name="Sinkronisasi Database" :scope-name="$selectedBranch->name" :sync-url="route('database.sync')" :status-url="route('database.sync-status', ['branch_id' => $selectedBranchId])" :status="$syncStatus" :branch-id="$selectedBranchId" :can-sync="$canSync" />
            </x-slot:actions>
        @endif
    </x-crm.toolbar>

    @if($selectedBranch)
    <x-crm.sync-status-panel module-key="database" :scope-name="$selectedBranch->name" :branch-id="$selectedBranchId" :status="$syncStatus" :is-stale="$isStale" />
    @endif

    @if($errors->any())
    <x-crm.alert variant="error" title="Data belum dapat disimpan" class="mb-4">
        <ul class="list-disc pl-5 mt-1">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-crm.alert>
    @endif

    @if($selectedBranch && !empty($sheetNames))
    @php
        $firstSheet = $sheetNames[0] ?? '';
        $initialRows = $records[$firstSheet] ?? [];
        $initialSample = $initialRows[0] ?? null;
        $initialHeaders = $initialSample ? $initialSample->headers : [];
        $initialFormulaCols = $initialSample ? ($initialSample->formula_columns ?? []) : [];
        $initialColumnMetadata = $initialSample ? ($initialSample->column_metadata ?? []) : [];
        $initialRecordsJson = json_encode(array_map(fn($r) => [
            'id' => $r->id,
            'row_number' => $r->row_number,
            'oasis_sync_id' => $r->oasis_sync_id,
            'updated_at' => $r->updated_at?->copy()->utc()->format('Y-m-d H:i:s'),
            'row_data' => $r->row_data,
        ], $initialRows));
        $initialHeadersJson = json_encode($initialHeaders);
        $initialFormulaJson = json_encode($initialFormulaCols);
        $initialColumnMetadataJson = json_encode($initialColumnMetadata);
    @endphp
    <div x-data="databaseTabs({
        branchId: '{{ $selectedBranch->id }}',
        editBaseUrl: '{{ url('database/records') }}',
        sheetDataBaseUrl: '{{ url('database/sheet') }}',
        firstSheet: '{{ $firstSheet }}',
        initialHeaders: {{ $initialHeadersJson }},
        initialFormulaCols: {{ $initialFormulaJson }},
        initialColumnMetadata: {{ $initialColumnMetadataJson }},
        initialRecords: {{ $initialRecordsJson }},
        requestSheet: '{{ $requestSheet ?? '' }}',
        requestAdd: {{ ($requestAdd ?? false) ? 'true' : 'false' }},
        canEdit: {{ $canEdit ? 'true' : 'false' }}
    })" @oasis-sync-updated.window="handleSyncUpdated($event.detail)">
        <div class="database-tabs" role="tablist" aria-label="Sheet Database">
            @foreach($sheetNames as $name)
            <button type="button"
                    role="tab"
                    data-database-tab
                    @click="switchTab(@js($name))"
                    @keydown.right.prevent="focusAdjacentTab(@js($name), 1)"
                    @keydown.left.prevent="focusAdjacentTab(@js($name), -1)"
                    @keydown.home.prevent="focusTabAt(0)"
                    @keydown.end.prevent="focusTabAt(sheetNameList.length - 1)"
                    :id="tabButtonId(@js($name))"
                    :aria-controls="tabPanelId(@js($name))"
                    :aria-selected="tab === @js($name)"
                    :tabindex="tab === @js($name) ? 0 : -1"
                    :class="{ 'active': tab === @js($name), 'loading': loading && tab === @js($name) }"
                    class="database-tab">
                {{ $name }} <span :class="tab === @js($name) ? 'text-black/60' : 'text-gray-500'" class="database-tab-count">(<span x-text="sheetCount(@js($name))">0</span>)</span>
            </button>
            @endforeach
        </div>

        <div x-show="syncRefreshError" x-cloak class="mb-3 border-2 border-black bg-[#fcc20f] px-3 py-2 text-sm" aria-live="polite">
            <span x-text="syncRefreshError"></span>
            <button type="button" @click="refreshActiveSheet(tab)" class="ml-2 border-2 border-black bg-white px-2 py-1 text-xs font-bold">Coba Muat Ulang Data</button>
        </div>

        {{-- Tab content -- rendered per sheet name --}}
        <template x-for="name in sheetNameList" :key="name">
            <section x-show="tab === name"
                     x-cloak
                     role="tabpanel"
                     :id="tabPanelId(name)"
                     :aria-labelledby="tabButtonId(name)"
                     :aria-busy="inFlight[name] ? 'true' : 'false'"
                     class="database-sheet-panel">
                <x-crm.toolbar label="Pencarian dan tindakan sheet" class="database-sheet-toolbar">
                    <template x-if="isLoaded(name)">
                        <div class="database-search-group">
                            <label :for="searchInputId(name)" class="crm-type-label">Cari konsumen</label>
                            <div class="database-search-control">
                                <input type="search"
                                       :id="searchInputId(name)"
                                       x-model="filterText"
                                       @keydown.escape="clearSearch()"
                                       placeholder="Nama, kontak, kavling, atau data lain..."
                                       class="crm-control database-search-input">
                                <x-crm.button x-show="filterText" x-cloak size="sm" variant="text" @click="clearSearch()" aria-label="Hapus pencarian">Hapus</x-crm.button>
                            </div>
                            <p class="database-result-count" aria-live="polite">
                                <span x-text="'Menampilkan ' + sortedRecords(name).length + ' dari ' + currentData(name).records.length + ' data'"></span>
                            </p>
                        </div>
                    </template>

                    <x-slot:actions>
                        <template x-if="canAdd(name)">
                            <x-crm.button accent="database" variant="primary" size="sm" @click="openAdd(name, $el)">Tambah Data</x-crm.button>
                        </template>
                        <x-crm.button
                            variant="secondary"
                            size="sm"
                            href="https://docs.google.com/spreadsheets/d/{{ $selectedBranch->sheet_id }}"
                            target="_blank"
                            rel="noopener noreferrer"
                        >Buka Google Sheet</x-crm.button>
                    </x-slot:actions>
                </x-crm.toolbar>

                <div x-show="filterText && isLoaded(name)" x-cloak class="database-active-filters" aria-label="Pencarian aktif">
                    <x-crm.filter-chip>
                        <span x-text="'Pencarian: ' + filterText"></span>
                        <x-slot:remove>
                            <button type="button" class="crm-filter-chip-remove" @click="clearSearch()" aria-label="Hapus pencarian">&times;</button>
                        </x-slot:remove>
                    </x-crm.filter-chip>
                </div>

                <div x-show="!isLoaded(name) && !loadErrors[name]" class="database-table-state" aria-live="polite">
                    <x-crm.loading-state label="Memuat data Database..." />
                    <div x-show="inFlight[name]" class="mt-3 space-y-2" aria-hidden="true"><div class="h-3 bg-gray-200 animate-pulse motion-reduce:animate-none"></div><div class="h-3 bg-gray-200 animate-pulse motion-reduce:animate-none"></div><div class="h-3 bg-gray-200 animate-pulse motion-reduce:animate-none"></div></div>
                </div>
                <x-crm.alert x-show="loadErrors[name]" x-cloak variant="error" title="Data gagal dimuat" aria-live="polite">
                    <p>Tabel tidak dianggap kosong karena permintaan data belum berhasil.</p>
                    <div class="crm-alert-actions"><x-crm.button type="button" size="sm" @click="switchTab(name, true)">Coba Lagi</x-crm.button><x-crm.button type="button" size="sm" @click="window.dispatchEvent(new CustomEvent('open-feedback'))">Laporkan Masalah</x-crm.button></div>
                </x-crm.alert>

                <template x-if="isLoaded(name) && currentData(name).records.length > 0">
                    <div class="crm-table-scroll" :data-sheet-scroll="name">
                        <table class="crm-data-table db-table" :class="{ frozen: frozen }">
                            <caption class="sr-only" x-text="'Data konsumen pada sheet ' + name + ', cabang ' + @js($selectedBranch->name)"></caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="crm-row-num" :class="{ 'row-num': frozen }">Baris</th>
                                    <template x-for="h in currentData(name).headers" :key="h">
                                        <th scope="col"
                                            x-show="!['oasis_sync_id','oasis_deleted_at','oasis_deleted_by'].includes(h)"
                                            :class="(currentData(name).formula_columns.includes(h) ? 'formula-col ' : '') + (isIdKavlingColumn(h) ? 'col-id_kavling' : '')"
                                            :aria-sort="sortAria(h)">
                                            <button type="button" class="database-sort-button" @click="sortBy(h)" :aria-label="sortLabel(h)">
                                                <span x-text="h"></span><span class="database-sort-indicator" aria-hidden="true" x-text="sortIcon(h)"></span>
                                            </button>
                                            <button type="button"
                                                    x-show="isIdKavlingColumn(h)"
                                                    @click.stop="frozen = !frozen"
                                                    class="database-freeze-toggle"
                                                    :aria-pressed="frozen"
                                                    :aria-label="frozen ? 'Lepaskan kolom ID Kavling' : 'Bekukan kolom ID Kavling'"
                                                    :title="frozen ? 'Lepaskan kolom ID Kavling' : 'Bekukan kolom ID Kavling'">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="10" width="14" height="10" /><path :d="frozen ? 'M8 10V7a4 4 0 0 1 8 0v3' : 'M8 10V7a4 4 0 0 1 7.5-2'" /></svg>
                                            </button>
                                            <template x-if="currentData(name).formula_columns.includes(h)">
                                                <span class="database-formula-label">Formula</span>
                                            </template>
                                        </th>
                                    </template>
                                    @if($canEdit)<th scope="col" class="crm-actions">Aksi</th>@endif
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="rec in sortedRecords(name)" :key="rec.id">
                                    <tr>
                                        <td class="crm-row-num" :class="{ 'row-num': frozen }" x-text="rec.row_number"></td>
                                        <template x-for="h in currentData(name).headers" :key="h">
                                            <td x-show="!['oasis_sync_id','oasis_deleted_at','oasis_deleted_by'].includes(h)"
                                                :class="(isIdKavlingColumn(h) ? 'col-id_kavling ' : '') + (currentData(name).formula_columns.includes(h) ? 'database-formula-cell' : '')"
                                                :title="rec.row_data[h] || ''">
                                                  <template x-if="isBooleanValue(rec.row_data[h])">
                                                       <span class="crm-boolean-box"
                                                             :class="cellValue(rec.row_data[h]).toLowerCase() === 'true' ? 'is-checked' : ''"
                                                             :aria-label="cellValue(rec.row_data[h]).toLowerCase() === 'true' ? 'Ya' : 'Tidak'"
                                                             x-text="cellValue(rec.row_data[h]).toLowerCase() === 'true' ? '✓' : ''">
                                                       </span>
                                                   </template>
                                                   <template x-if="!isBooleanValue(rec.row_data[h])">
                                                    <span x-text="rec.row_data[h] || ''"></span>
                                                </template>
                                            </td>
                                        </template>
                                        @if($canEdit)<td class="crm-actions">
                                            <button type="button" @click="editRecord(rec, $el)" class="crm-table-action crm-table-action--edit">Edit</button>
                                            <form method="POST" :action="editBaseUrl + '/' + rec.id" style="display:inline;" @submit.prevent="confirm('Hapus data ini?') && $el.submit()">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="crm-table-action crm-table-action--danger">Hapus</button>
                                            </form>
                                        </td>@endif
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </template>

                <template x-if="isLoaded(name) && currentData(name).records.length === 0">
                    <x-crm.empty-state title="Sheet belum memiliki data" description="Tambahkan minimal satu baris contoh di Google Sheet, lalu sinkronkan agar form tambah data dapat dibuat." />
                </template>

                <template x-if="isLoaded(name) && currentData(name).records.length > 0 && sortedRecords(name).length === 0">
                    <x-crm.empty-state title="Tidak ada konsumen yang cocok" description="Tidak ada konsumen yang sesuai dengan pencarian ini.">
                        <x-slot:actions>
                            <x-crm.button size="sm" @click="clearSearch()">Hapus pencarian</x-crm.button>
                        </x-slot:actions>
                    </x-crm.empty-state>
                </template>
            </section>
        </template>

        {{-- Edit Modal --}}
        <div x-cloak x-show="editing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4"
             @keydown.escape.window="editing = null">
            <div x-ref="editPanel" @click.away="editing = null"
                 class="w-full max-w-2xl border-2 border-black bg-white p-5 shadow-[8px_8px_0_0_#000] max-h-[80vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-[Helvetica] font-bold text-sm uppercase" x-text="'Edit — ' + tab"></h2>
                    <button @click="editing = null" class="text-black font-bold text-lg leading-none">&times;</button>
                </div>
                <div x-show="newerDataAvailable" x-cloak class="mb-3 border-2 border-black bg-[#fcc20f] px-3 py-2 text-sm" aria-live="polite">
                    <p class="font-bold">Data terbaru tersedia setelah sinkronisasi.</p>
                    <p>Draf edit Anda tetap dipertahankan. Muat ulang hanya jika Anda siap meninggalkan draf ini.</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" @click="reloadNewerData()" class="border-2 border-black bg-black px-2 py-1 text-xs font-bold text-white">Muat Ulang Data</button>
                        <button type="button" @click="newerDataAvailable = false" class="border-2 border-black bg-white px-2 py-1 text-xs font-bold">Pertahankan Draf</button>
                    </div>
                </div>
                <form method="POST" :action="editBaseUrl + '/' + editing.id" data-conflict-form
                      @submit.prevent="$dispatch('oasis-submit-conflict', { form: $el })">
                    @csrf @method('PUT')
                    <input type="hidden" name="expected_updated_at" :value="editing.updated_at">
                    <input type="hidden" name="expected_sync_id" :value="editing.oasis_sync_id">
                    <template x-if="editing"><div x-data="crmPresence(@js(['enabled' => config('presence.enabled', true), 'heartbeatUrl' => route('presence.heartbeat'), 'indexUrl' => route('presence.index'), 'destroyUrl' => route('presence.destroy'), 'heartbeatSeconds' => config('presence.heartbeat_seconds', 25), 'pageKey' => 'database', 'branchId' => null, 'recordType' => 'database_sheet_record', 'recordId' => null, 'mode' => 'editing']))" x-init="updateContext({ branchId: branchId, recordType: 'database_sheet_record', recordId: editing.id, mode: 'editing' })" x-show="others.length" :title="fullNames" class="mb-3 border-2 border-black bg-[#eef1ff] px-3 py-2 text-xs"><span class="font-bold" x-text="summary"></span><span class="block text-[#8a4b08]">Perubahan terakhir akan diperiksa saat menyimpan.</span></div></template>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <template x-for="h in editableHeaders()" :key="h">
                            <div>
                                <label class="font-[Helvetica] font-bold text-[10px] uppercase block mb-0.5" x-text="h"></label>
                                 <template x-if="fieldType(tab, h, editForm[h]) === 'checkbox'">
                                      <label class="flex items-center gap-2 cursor-pointer">
                                          <input type="hidden" :name="h" :value="editForm[h]">
                                          <input type="checkbox"
                                                 :checked="isChecked(tab, h, editForm[h])"
                                                 @change="editForm[h] = $event.target.checked ? checkedValue(tab, h) : uncheckedValue(tab, h)"
                                                 class="w-5 h-5 accent-[#5d8e8e] border-2 border-black cursor-pointer rounded-none">
                                          <span class="text-xs font-['Times_New_Roman']" x-text="isChecked(tab, h, editForm[h]) ? 'Aktif' : 'Tidak'"></span>
                                      </label>
                                 </template>
                                 <template x-if="fieldType(tab, h, editForm[h]) === 'select'">
                                    <select :name="h" x-model="editForm[h]"
                                            class="w-full border-2 border-black px-2 py-1 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                                        <option value="">— Pilih —</option>
                                        <template x-for="option in fieldOptions(tab, h, editForm[h])" :key="option">
                                            <option :value="option" x-text="option"></option>
                                        </template>
                                     </select>
                                 </template>
                                 <template x-if="fieldType(tab, h, editForm[h]) === 'date'">
                                    <div class="date-wrapper" data-accent="#d77a7a" style="position:relative">
                                        <div class="date-display w-full border-2 border-black px-2 py-1 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between" tabindex="0">
                                            <span class="date-text">— Pilih Tanggal —</span>
                                            <span class="date-arrow">▼</span>
                                        </div>
                                        <input type="date" :name="h" x-model="editForm[h]"
                                               style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                                    </div>
                                 </template>
                                 <template x-if="fieldType(tab, h, editForm[h]) === 'time'">
                                    <div class="time-wrapper" data-accent="#d77a7a">
                                        <div class="time-display w-full border-2 border-black px-2 py-1 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between" tabindex="0" role="button" aria-haspopup="dialog"><span class="time-text">Pilih Jam</span><span class="time-arrow">▼</span></div>
                                        <input type="time" :name="h" x-model="editForm[h]" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                                    </div>
                                 </template>
                                 <template x-if="!['checkbox', 'select', 'date', 'time'].includes(fieldType(tab, h, editForm[h]))">
                                    <input :type="fieldType(tab, h, editForm[h])" :name="h" x-model="editForm[h]"
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
                                     <template x-if="fieldType(name, h) === 'checkbox'">
                                         <label class="flex items-center gap-2 cursor-pointer">
                                             <input type="hidden" :name="h" :value="uncheckedValue(name, h)">
                                             <input type="checkbox" :name="h" :value="checkedValue(name, h)"
                                                    @change="$event.target.nextElementSibling.textContent = $event.target.checked ? 'Aktif' : 'Tidak'"
                                                    class="w-5 h-5 accent-[#5d8e8e] border-2 border-black cursor-pointer rounded-none">
                                             <span class="text-xs font-['Times_New_Roman']">Tidak</span>
                                         </label>
                                     </template>
                                     <template x-if="fieldType(name, h) === 'select'">
                                        <select :name="h"
                                                class="w-full border-2 border-black px-2 py-1 text-sm font-['Times_New_Roman'] bg-white rounded-none">
                                            <option value="">— Pilih —</option>
                                            <template x-for="option in fieldOptions(name, h)" :key="option">
                                                <option :value="option" x-text="option"></option>
                                            </template>
                                         </select>
                                     </template>
                                     <template x-if="fieldType(name, h) === 'date'">
                                        <div class="date-wrapper" data-accent="#d77a7a" style="position:relative">
                                            <div class="date-display w-full border-2 border-black px-2 py-1 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between" tabindex="0">
                                                <span class="date-text">— Pilih Tanggal —</span>
                                                <span class="date-arrow">▼</span>
                                            </div>
                                            <input type="date" :name="h" value=""
                                                   style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                                        </div>
                                     </template>
                                     <template x-if="fieldType(name, h) === 'time'">
                                        <div class="time-wrapper" data-accent="#d77a7a">
                                            <div class="time-display w-full border-2 border-black px-2 py-1 text-sm font-['Times_New_Roman'] bg-white cursor-pointer select-none flex items-center justify-between" tabindex="0" role="button" aria-haspopup="dialog"><span class="time-text">Pilih Jam</span><span class="time-arrow">▼</span></div>
                                            <input type="time" :name="h" value="" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
                                        </div>
                                     </template>
                                     <template x-if="!['checkbox', 'select', 'date', 'time'].includes(fieldType(name, h))">
                                        <input :type="fieldType(name, h)" :name="h" value=""
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
        loadErrors: {},
        inFlight: {},
        syncRefreshError: null,
        newerDataAvailable: false,
        pendingRefreshSheet: null,
        sortColumn: null,
        sortDirection: 'asc',
        filterText: '',
        frozen: true,

        init() {
            this.cache[config.firstSheet] = {
                headers: config.initialHeaders,
                formula_columns: config.initialFormulaCols,
                column_metadata: config.initialColumnMetadata,
                records: config.initialRecords,
            };
            this.loaded[config.firstSheet] = true;
            this.sheetNameList = @json($sheetNames);

            if (config.requestSheet) {
                const lowerRequest = config.requestSheet.toLowerCase();
                const match = this.sheetNameList.find(s => s.toLowerCase() === lowerRequest);
                if (match) {
                    if (match !== config.firstSheet) {
                        this.$nextTick(() => this.switchTabWithAdd(match, config.requestAdd));
                    } else if (config.requestAdd && this.canAdd(this.tab)) {
                        this.$nextTick(() => { this.adding = this.tab; });
                    }
                }
            }
        },

        async switchTabWithAdd(name, doAdd) {
            await this.switchTab(name);
            if (doAdd && this.canAdd(name)) this.adding = name;
        },

        canAdd(name) {
            const data = this.cache[name];
            return config.canEdit && !!(data && data.records && data.records.length > 0);
        },

        openAdd(name, trigger = null) {
            this.adding = name;
            this.addTrigger = trigger;
        },

        clearSearch() {
            this.filterText = '';
        },

        searchInputId(name) {
            return `database-search-${String(name).toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
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

        async switchTab(name, force = false) {
            this.tab = name;
            if (this.loaded[name] && !force) return;
            if (this.inFlight[name]) return;
            this.loadErrors[name] = false;
            this.inFlight[name] = true;
            this.loading = true;
            try {
                const res = await fetch(`{{ url('database/sheet') }}/${this.branchId}/${encodeURIComponent(name)}`, { headers: { Accept: 'application/json' } });
                if (!res.ok) throw new Error('Data gagal dimuat');
                const data = await res.json();
                this.cache[name] = data;
                this.loaded[name] = true;
            } catch (e) {
                this.loadErrors[name] = true;
                this.loaded[name] = false;
            } finally {
                this.inFlight[name] = false;
                this.loading = false;
            }
        },

        async handleSyncUpdated(detail) {
            if (detail.module_key !== 'database' || detail.status !== 'success') return;
            if (String(detail.scope?.id ?? '') !== String(this.branchId)) return;
            const sheet = this.tab;
            if (!sheet) return;
            if (this.editing) {
                this.pendingRefreshSheet = sheet;
                this.newerDataAvailable = true;
                return;
            }
            await this.refreshActiveSheet(sheet);
        },

        async refreshActiveSheet(sheet = this.tab) {
            if (!sheet || !this.sheetNameList.includes(sheet)) return;
            const branchId = String(this.branchId);
            const scrollContainer = this.$root.querySelector(`[data-sheet-scroll="${CSS.escape(sheet)}"]`);
            const tableScrollLeft = scrollContainer?.scrollLeft || 0;
            const tableScrollTop = scrollContainer?.scrollTop || 0;
            const pageScrollY = window.scrollY;
            this.syncRefreshError = null;
            try {
                const response = await fetch(`${config.sheetDataBaseUrl}/${encodeURIComponent(branchId)}/${encodeURIComponent(sheet)}`, { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('Active sheet refresh failed');
                const data = await response.json();
                if (String(this.branchId) !== branchId || data.sheet_name !== sheet) throw new Error('Sync scope changed');
                this.cache[sheet] = data;
                this.loaded[sheet] = true;
                this.loadErrors[sheet] = false;
                this.$nextTick(() => {
                    if (this.tab !== sheet) return;
                    const refreshedScroll = this.$root.querySelector(`[data-sheet-scroll="${CSS.escape(sheet)}"]`);
                    if (refreshedScroll) {
                        refreshedScroll.scrollLeft = tableScrollLeft;
                        refreshedScroll.scrollTop = tableScrollTop;
                    }
                    window.scrollTo({ top: pageScrollY, behavior: 'auto' });
                });
            } catch (_) {
                this.syncRefreshError = 'Sinkronisasi berhasil, tetapi tabel belum dapat dimuat ulang.';
            }
        },

        reloadNewerData() {
            const sheet = this.pendingRefreshSheet || this.tab;
            this.editing = null;
            this.newerDataAvailable = false;
            this.pendingRefreshSheet = null;
            this.refreshActiveSheet(sheet);
        },

        editRecord(rec, trigger = null) {
            this.editing = rec;
            this.editTrigger = trigger;
            this.editForm = JSON.parse(JSON.stringify(rec.row_data));
            for (const header of this.editableHeaders()) {
                this.editForm[header] = this.normalizeInputValue(this.tab, header, this.editForm[header]);
            }
            this.$nextTick(() => this.$refs.editPanel?.querySelectorAll('input[type="date"], input[type="time"]').forEach(input => input.dispatchEvent(new Event('input', { bubbles: true }))));
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

        sortAria(header) {
            if (this.sortColumn !== header) return 'none';
            return this.sortDirection === 'asc' ? 'ascending' : 'descending';
        },

        sortLabel(header) {
            if (this.sortColumn !== header) return `Urutkan berdasarkan ${header}, menaik`;
            if (this.sortDirection === 'asc') return `Urutkan berdasarkan ${header}, menurun`;
            return `Hapus urutan ${header}`;
        },

        tabKey(name) {
            const index = this.sheetNameList.indexOf(name);
            return `${String(name).toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${index}`;
        },

        tabButtonId(name) {
            return `database-tab-${this.tabKey(name)}`;
        },

        tabPanelId(name) {
            return `database-panel-${this.tabKey(name)}`;
        },

        focusAdjacentTab(name, offset) {
            const index = this.sheetNameList.indexOf(name);
            const next = (index + offset + this.sheetNameList.length) % this.sheetNameList.length;
            this.focusTabAt(next);
        },

        focusTabAt(index) {
            const next = this.sheetNameList[index];
            if (!next) return;
            this.switchTab(next);
            this.$nextTick(() => document.getElementById(this.tabButtonId(next))?.focus());
        },

        cellValue(value) {
            return String(value || '');
        },

        isBooleanValue(value) {
            return ['true', 'false'].includes(this.cellValue(value).toLowerCase());
        },

        isBooleanColumn(sheetName, header) {
            const data = this.cache[sheetName];
            if (!data || !data.records) return false;
            return data.records.some(r =>
                ['true', 'false'].includes((r.row_data[header] || '').toLowerCase())
            );
        },

        columnMetadata(sheetName, header) {
            return this.cache[sheetName]?.column_metadata?.[header] || {};
        },

        fieldType(sheetName, header, value = '') {
            const type = this.columnMetadata(sheetName, header).type;
            if (['select', 'checkbox', 'date', 'datetime-local', 'time'].includes(type)) {
                return type;
            }
            if (['true', 'false'].includes(String(value || '').toLowerCase()) || this.isBooleanColumn(sheetName, header)) {
                return 'checkbox';
            }
            return 'text';
        },

        fieldOptions(sheetName, header, currentValue = '') {
            const options = [...(this.columnMetadata(sheetName, header).options || [])];
            const current = String(currentValue || '').trim();
            if (current && !options.includes(current)) options.push(current);
            return options;
        },

        checkedValue(sheetName, header) {
            return this.columnMetadata(sheetName, header).checked_value || 'true';
        },

        uncheckedValue(sheetName, header) {
            return this.columnMetadata(sheetName, header).unchecked_value || 'false';
        },

        isChecked(sheetName, header, value) {
            return String(value || '').toLowerCase() === String(this.checkedValue(sheetName, header)).toLowerCase();
        },

        normalizeInputValue(sheetName, header, value) {
            const type = this.fieldType(sheetName, header, value);
            const raw = String(value || '').trim();
            if (!raw || !['date', 'datetime-local', 'time'].includes(type)) return raw;

            if (type === 'time') {
                const time = raw.match(/(\d{1,2}):(\d{2})/);
                return time ? `${time[1].padStart(2, '0')}:${time[2]}` : raw;
            }

            const match = raw.match(/^(\d{1,4})[\/\-.](\d{1,2})[\/\-.](\d{1,4})(?:[ T](\d{1,2}):(\d{2}))?/);
            if (!match) return raw;

            const yearFirst = match[1].length === 4;
            const year = yearFirst ? match[1] : match[3];
            const month = (yearFirst ? match[2] : match[2]).padStart(2, '0');
            const day = (yearFirst ? match[3] : match[1]).padStart(2, '0');
            const date = `${year}-${month}-${day}`;

            return type === 'datetime-local'
                ? `${date}T${(match[4] || '00').padStart(2, '0')}:${match[5] || '00'}`
                : date;
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
