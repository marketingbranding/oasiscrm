@extends('layouts.crm')

@section('title', 'Database V2 - Oasis CRM')

@section('content')
    <x-crm.page-header
        variant="canonical"
        eyebrow="Sales / Database V2"
        title="Database V2"
        description="Entry data konsumen manual per modul. Data tersimpan di database OASIS."
        class="database-v2-page-header"
    >
        <x-slot:actions>
            @if($selectedBranch)
                <x-crm.status-badge variant="info">Cabang: {{ $selectedBranch->name }}</x-crm.status-badge>
            @else
                <x-crm.status-badge variant="warning">Cabang belum dipilih</x-crm.status-badge>
            @endif
        </x-slot:actions>
    </x-crm.page-header>

    <x-crm.toolbar label="Ruang kerja Database V2" class="database-v2-scope-toolbar">
        @if(isset($branches) && $branches->count() > 1)
            <form method="GET" action="{{ route('database-v2.index') }}" class="database-v2-branch-form">
                <label for="database-v2-branch" class="crm-type-label">Pilih Cabang</label>
                <select id="database-v2-branch" name="branch_id" onchange="this.form.submit()" class="crm-control database-v2-branch-select">
                    <option value="">— Pilih Cabang —</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ (string)$selectedBranchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }} ({{ $b->code }})</option>
                    @endforeach
                </select>
                <noscript><x-crm.button type="submit" size="sm">Terapkan</x-crm.button></noscript>
            </form>
        @elseif($selectedBranch)
            <div class="database-v2-scope-summary">
                <span class="crm-type-label">Ruang kerja aktif</span>
                <strong>Cabang: {{ $selectedBranch->name }} ({{ $selectedBranch->code }})</strong>
            </div>
        @endif
    </x-crm.toolbar>

    @if($selectedBranch)
    @php
        $moduleKeys = array_keys($modules);
        $firstModule = $moduleKeys[0] ?? '';
        $v2Config = [
            'branchId' => (string) $selectedBranch->id,
            'baseUrl' => url('database-v2'),
            'modules' => $moduleKeys,
            'moduleLabels' => array_map(fn($c) => $c['label'], $modules),
            'firstModule' => $firstModule,
            'labels' => $labels,
            'tableFields' => array_map(fn($c) => $c['table'], $modules),
            'formFields' => array_map(fn($c) => $c['fields'], $modules),
            'fullWidth' => array_map(fn($c) => $c['full_width'], $modules),
            'moneyFields' => array_map(fn($c) => $c['money'], $modules),
            'dateFields' => array_map(fn($c) => $c['date'], $modules),
            'integerFields' => array_map(fn($c) => $c['integer'], $modules),
            'canEdit' => (bool) $canEdit,
            'requestModule' => $requestModule ?? '',
            'requestAdd' => (bool) ($requestAdd ?? false),
        ];
    @endphp
    <div x-data="databaseV2(@js($v2Config))" @oasis:modal-closed.window="handleModalClosed($event.detail)">
        <div class="database-v2-tabs crm-horizontal-tabs" data-horizontal-tabs role="tablist" aria-label="Modul Database V2">
            @foreach($modules as $key => $cfg)
            <button type="button" role="tab" data-v2-tab @click="switchModule(@js($key))"
                    :id="tabButtonId(@js($key))" :aria-controls="tabPanelId(@js($key))"
                    :aria-selected="module === @js($key)" :tabindex="module === @js($key) ? 0 : -1"
                    :class="{ 'active': module === @js($key) }" class="database-v2-tab">
                {{ $cfg['label'] }} <span class="database-v2-tab-count">(<span x-text="moduleCount(@js($key))">0</span>)</span>
            </button>
            @endforeach
        </div>

        <template x-for="mod in moduleList" :key="mod">
            <section x-show="module === mod" x-cloak role="tabpanel" :id="tabPanelId(mod)" :aria-labelledby="tabButtonId(mod)" class="database-v2-panel">
                <x-crm.toolbar label="Pencarian dan tindakan modul" class="database-v2-sheet-toolbar">
                    <div class="database-v2-search-group">
                        <label :for="searchInputId(mod)" class="crm-type-label">Cari data</label>
                        <div class="database-v2-search-control">
                            <input type="search" :id="searchInputId(mod)" x-model="searchText" @input.debounce.300ms="searchMod(mod)"
                                   placeholder="Nama, NIK, kavling..." class="crm-control database-v2-search-input">
                            <x-crm.button x-show="searchText" x-cloak size="sm" variant="text" @click="clearSearch()">Hapus</x-crm.button>
                        </div>
                        <p class="database-v2-result-count" aria-live="polite" x-text="resultText(mod)"></p>
                    </div>
                    <x-slot:actions>
                        <x-crm.button accent="database" variant="primary" size="sm" @click="openAdd(mod, $el)">Tambah Data</x-crm.button>
                        @if($canEdit)
                        <x-crm.button variant="secondary" size="sm" @click="openImport(mod, $el)">Import Copas</x-crm.button>
                        @endif
                        <x-crm.button variant="secondary" size="sm" @click="exportMod(mod)">Export Excel</x-crm.button>
                    </x-slot:actions>
                </x-crm.toolbar>

                <div x-show="!loaded[mod] && !loadErrors[mod]" class="database-v2-table-state">
                    <x-crm.loading-state :label="'Memuat data...'" />
                </div>
                <x-crm.alert x-show="loadErrors[mod]" x-cloak variant="error" title="Data gagal dimuat." aria-live="polite">
                    <p x-text="'Gagal memuat data ' + moduleLabel(mod) + '.'"></p>
                </x-crm.alert>

                <template x-if="loaded[mod] && records[mod].length > 0">
                    <div class="crm-table-scroll">
                        <table class="crm-data-table db-v2-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="crm-row-num">No</th>
                                    <template x-for="h in tableFields[mod]" :key="h">
                                        <th scope="col" x-text="fieldLabel(h)"></th>
                                    </template>
                                    @if($canEdit)<th scope="col" class="crm-actions">Aksi</th>@endif
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(rec, idx) in records[mod]" :key="rec.id">
                                    <tr>
                                        <td class="crm-row-num" x-text="(page[mod] - 1) * perPage + idx + 1"></td>
                                        <template x-for="h in tableFields[mod]" :key="h">
                                            <td x-text="formatCell(mod, h, rec[h])" :title="rec[h] || ''"></td>
                                        </template>
                                        @if($canEdit)<td class="crm-actions">
                                            <button type="button" @click="editRecord(mod, rec, $el)" class="crm-table-action crm-table-action--edit">Edit</button>
                                            <button type="button" @click="archiveRecord(mod, rec.id)" class="crm-table-action crm-table-action--danger">Arsipkan</button>
                                        </td>@endif
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </template>

                <template x-if="loaded[mod] && records[mod].length === 0">
                    <x-crm.empty-state :title="'Belum ada data.'" :description="'Tambahkan data baru atau import dari spreadsheet.'" />
                </template>

                @if($canEdit)
                <div x-show="pagination[mod] > 1" x-cloak class="database-v2-pagination">
                    <x-crm.button size="sm" variant="secondary" x-bind:disabled="page[mod] <= 1" @click="changePage(mod, page[mod] - 1)">Sebelumnya</x-crm.button>
                    <span x-text="page[mod] + ' / ' + pagination[mod]"></span>
                    <x-crm.button size="sm" variant="secondary" x-bind:disabled="page[mod] >= pagination[mod]" @click="changePage(mod, page[mod] + 1)">Berikutnya</x-crm.button>
                </div>
                @endif
            </section>
        </template>

        <x-crm.modal name="database-v2-edit" title="Edit Data" size="xl">
            <div x-show="editing" x-cloak>
                <p class="database-v2-modal-scope" x-text="moduleLabel(module)"></p>
                <form id="database-v2-edit-form" @submit.prevent="submitEdit()">
                    @csrf @method('PUT')
                    <input type="hidden" name="module" :value="module">
                    <input type="hidden" name="branch_id" :value="branchId">
                    <input type="hidden" name="id" :value="editingId">
                    <div class="database-v2-dynamic-fields">
                        <template x-for="h in formFields[module]" :key="h">
                            <div class="crm-field" :class="{ 'crm-field--full': isFullWidth(module, h) }">
                                <label :for="fieldId('edit', h)" class="crm-field-label" x-text="fieldLabel(h)"></label>
                                <template x-if="isDate(h)">
                                    <div class="date-wrapper" data-accent="#d77a7a"><button type="button" class="date-display crm-control"><span class="date-text">— Pilih Tanggal —</span><span class="date-arrow">▼</span></button><input type="date" :id="fieldId('edit', h)" :name="h" x-model="editForm[h]" class="sr-only"></div>
                                </template>
                                <template x-if="!isDate(h)">
                                    <input :type="isNumber(h) ? 'number' : 'text'" :id="fieldId('edit', h)" :name="h" x-model="editForm[h]" class="crm-control" :step="isMoney(h) ? '0.01' : '1'">
                                </template>
                            </div>
                        </template>
                    </div>
                </form>
            </div>
            <x-slot:footer><x-crm.button type="button" @click="closeEdit()">Batal</x-crm.button><x-crm.button type="submit" form="database-v2-edit-form" accent="database" variant="primary">Simpan</x-crm.button></x-slot:footer>
        </x-crm.modal>

        <x-crm.modal name="database-v2-add" title="Tambah Data" size="xl">
            <div x-show="adding" x-cloak>
                <p class="database-v2-modal-scope" x-text="moduleLabel(adding)"></p>
                <form id="database-v2-add-form" @submit.prevent="submitAdd()">
                    @csrf
                    <input type="hidden" name="module" :value="adding || ''">
                    <input type="hidden" name="branch_id" :value="branchId">
                    <div class="database-v2-dynamic-fields">
                        <template x-for="h in formFields[adding]" :key="h">
                            <div class="crm-field" :class="{ 'crm-field--full': isFullWidth(adding, h) }">
                                <label :for="fieldId('add', h)" class="crm-field-label" x-text="fieldLabel(h)"></label>
                                <template x-if="isDate(h)">
                                    <div class="date-wrapper" data-accent="#d77a7a"><button type="button" class="date-display crm-control"><span class="date-text">— Pilih Tanggal —</span><span class="date-arrow">▼</span></button><input type="date" :id="fieldId('add', h)" :name="h" x-model="addForm[h]" class="sr-only"></div>
                                </template>
                                <template x-if="!isDate(h)">
                                    <input :type="isNumber(h) ? 'number' : 'text'" :id="fieldId('add', h)" :name="h" x-model="addForm[h]" class="crm-control" :step="isMoney(h) ? '0.01' : '1'">
                                </template>
                            </div>
                        </template>
                    </div>
                </form>
            </div>
            <x-slot:footer><x-crm.button type="button" @click="closeAdd()">Batal</x-crm.button><x-crm.button type="submit" form="database-v2-add-form" accent="database" variant="primary">Simpan</x-crm.button></x-slot:footer>
        </x-crm.modal>

        @if($canEdit)
        <x-crm.modal name="database-v2-import" title="Import Copas" size="xl">
            <div x-show="importing" x-cloak>
                <p class="database-v2-modal-scope" x-text="moduleLabel(importing)"></p>
                <div x-show="importError" x-cloak class="database-v2-import-error" x-text="importError"></div>
                <form @submit.prevent="previewImport()">
                    @csrf
                    <input type="hidden" name="module" :value="importing || ''">
                    <input type="hidden" name="branch_id" :value="branchId">
                    <div class="crm-field">
                        <label for="database-v2-import-raw" class="crm-field-label">Tempel data (dipisah tab)</label>
                        <textarea id="database-v2-import-raw" name="raw" rows="12" x-model="importRaw" class="crm-control database-v2-import-textarea" placeholder="id_kavling	no_ktp	nama_konsumen&#10;A01	3374	Budi"></textarea>
                        <p class="crm-field-hint">Baris pertama adalah header. Pisahkan kolom dengan tab. Maksimal 1000 baris.</p>
                    </div>
                    <x-crm.button type="submit" variant="secondary" size="sm" x-bind:disabled="!importRaw">Preview</x-crm.button>
                </form>

                <template x-if="importPreview">
                    <div class="database-v2-import-preview">
                        <div class="database-v2-import-summary">
                            <x-crm.status-badge variant="success"><span x-text="importPreview.valid_count"></span> valid</x-crm.status-badge>
                            <x-crm.status-badge variant="danger"><span x-text="importPreview.invalid_count"></span> invalid</x-crm.status-badge>
                        </div>
                        <template x-if="importPreview.ignored_headers && importPreview.ignored_headers.length > 0">
                            <div class="database-v2-import-ignored">
                                <p class="crm-field-hint">Kolom diabaikan (legacy/system):</p>
                                <div class="database-v2-import-ignored-list">
                                    <template x-for="h in importPreview.ignored_headers" :key="h">
                                        <span class="database-v2-ignored-chip" x-text="h + ' → Diabaikan (Legacy)'"></span>
                                    </template>
                                </div>
                            </div>
                        </template>
                        <div class="crm-table-scroll">
                            <table class="crm-data-table">
                                <thead><tr><th class="crm-row-num">Baris</th><template x-for="h in importPreview.headers" :key="h"><th x-text="fieldLabel(h)"></th></template><th>Status</th></tr></thead>
                                <tbody>
                                    <template x-for="row in importPreview.rows" :key="row.line">
                                        <tr><td class="crm-row-num" x-text="row.line"></td><template x-for="h in importPreview.headers" :key="h"><td x-text="row.values[h] || ''"></td></template><td><span x-text="row.status" :class="row.status === 'VALID' ? 'crm-status-success' : 'crm-status-error'"></span></td></tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <div class="database-v2-import-actions">
                            <x-crm.button type="button" variant="secondary" @click="backToImportEdit()">Kembali Edit</x-crm.button>
                            <template x-if="importPreview.has_invalid">
                                <x-crm.button type="button" variant="secondary" @click="saveImportValidOnly()">Import Valid Rows Only</x-crm.button>
                            </template>
                            <x-crm.button type="button" accent="database" variant="primary" x-bind:disabled="importPreview.valid_count === 0" @click="saveImportAll()">Simpan Semua</x-crm.button>
                        </div>
                    </div>
                </template>
            </div>
            <x-slot:footer>
                <x-crm.button type="button" @click="closeImport()">Batal</x-crm.button>
            </x-slot:footer>
        </x-crm.modal>
        @endif
    </div>
    @elseif(!$selectedBranch)
        <x-crm.empty-state title="Cabang belum dipilih" description="Silakan pilih cabang terlebih dahulu." />
    @endif
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('databaseV2', (config) => ({
        module: config.firstModule,
        branchId: config.branchId,
        baseUrl: config.baseUrl,
        moduleList: config.modules,
        moduleLabels: config.moduleLabels,
        labels: config.labels,
        tableFields: config.tableFields,
        formFields: config.formFields,
        fullWidth: config.fullWidth,
        moneyFields: config.moneyFields,
        dateFields: config.dateFields,
        integerFields: config.integerFields,
        canEdit: config.canEdit,
        loading: false,
        loaded: {},
        loadErrors: {},
        records: {},
        total: {},
        page: {},
        pagination: {},
        perPage: 50,
        searchText: '',
        editing: false,
        editingId: null,
        editForm: {},
        adding: null,
        importing: null,
        importRaw: '',
        importPreview: null,
        importError: '',

        init() {
            this.loadModule(this.module);
            if (config.requestModule && config.requestModule !== config.firstModule) {
                this.$nextTick(() => this.switchModule(config.requestModule, config.requestAdd));
            } else if (config.requestAdd && this.canEdit) {
                this.$nextTick(() => this.openAdd(this.module));
            }
        },

        async switchModule(mod, doAdd = false) {
            this.module = mod;
            if (!this.loaded[mod]) {
                await this.loadModule(mod);
            }
            if (doAdd && this.canEdit) {
                this.$nextTick(() => this.openAdd(mod));
            }
        },

        async loadModule(mod) {
            if (this.loaded[mod]) return;
            this.loading = true;
            this.loadErrors[mod] = false;
            try {
                const params = new URLSearchParams({ branch_id: this.branchId, page: this.page[mod] || 1 });
                const res = await fetch(`${this.baseUrl}/${mod}/list?${params}`, { headers: { Accept: 'application/json' } });
                if (!res.ok) throw new Error('Load failed');
                const data = await res.json();
                this.records[mod] = data.records;
                this.total[mod] = data.total;
                this.page[mod] = data.page;
                this.pagination[mod] = data.last_page;
                this.loaded[mod] = true;
            } catch (e) {
                this.loadErrors[mod] = true;
            } finally {
                this.loading = false;
            }
        },

        async searchMod(mod) {
            this.page[mod] = 1;
            this.loaded[mod] = false;
            await this.loadModule(mod);
        },

        clearSearch() {
            this.searchText = '';
            this.searchMod(this.module);
        },

        async changePage(mod, newPage) {
            if (newPage < 1 || newPage > (this.pagination[mod] || 1)) return;
            this.page[mod] = newPage;
            this.loaded[mod] = false;
            await this.loadModule(mod);
        },

        moduleCount(mod) {
            return this.total[mod] || 0;
        },

        resultText(mod) {
            const total = this.total[mod] || 0;
            const shown = (this.records[mod] || []).length;
            return `Menampilkan ${shown} dari ${total} data`;
        },

        moduleLabel(mod) {
            return this.moduleLabels[mod] || mod;
        },

        fieldLabel(h) {
            return this.labels[h] || h;
        },

        isDate(h) {
            const mod = this.module;
            return (this.dateFields[mod] || []).includes(h);
        },

        isMoney(h) {
            const mod = this.module;
            return (this.moneyFields[mod] || []).includes(h);
        },

        isNumber(h) {
            const mod = this.module;
            return (this.moneyFields[mod] || []).includes(h) || (this.integerFields[mod] || []).includes(h);
        },

        isFullWidth(mod, h) {
            return (this.fullWidth[mod] || []).includes(h);
        },

        formatCell(mod, h, value) {
            if (value === null || value === undefined || value === '') return '';
            if ((this.moneyFields[mod] || []).includes(h)) {
                const num = parseFloat(String(value).replace(/[^\d.-]/g, ''));
                return isNaN(num) ? String(value) : 'Rp ' + num.toLocaleString('id-ID');
            }
            if ((this.dateFields[mod] || []).includes(h)) {
                const m = String(value).match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
                if (m) return `${m[3].padStart(2,'0')}/${m[2].padStart(2,'0')}/${m[1]}`;
            }
            return String(value);
        },

        fieldId(mode, h) {
            return `dbv2-${mode}-${h}`;
        },

        searchInputId(mod) {
            return `dbv2-search-${mod}`;
        },

        tabButtonId(mod) {
            return `dbv2-tab-${mod}`;
        },

        tabPanelId(mod) {
            return `dbv2-panel-${mod}`;
        },

        openAdd(mod, trigger = null) {
            this.adding = mod;
            this.$nextTick(() => this.$dispatch('oasis:modal-open', { name: 'database-v2-add', trigger }));
        },

        closeAdd() {
            this.$dispatch('oasis:modal-close', { name: 'database-v2-add', reason: 'cancel' });
        },

        async submitAdd() {
            const form = document.getElementById('database-v2-add-form');
            const fd = new FormData(form);
            const mod = fd.get('module');
            try {
                const res = await fetch(`${this.baseUrl}/${mod}`, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: fd,
                });
                const data = await res.json();
                if (!res.ok) { window.oasisToast?.(data.message || 'Gagal menyimpan.', 'error'); return; }
                window.oasisToast?.(data.message || 'Data berhasil ditambahkan.', 'success');
                this.closeAdd();
                this.loaded[mod] = false;
                await this.loadModule(mod);
            } catch (e) {
                window.oasisToast?.('Gagal menyimpan. Coba lagi.', 'error');
            }
        },

        editRecord(mod, rec, trigger = null) {
            this.editing = true;
            this.editingId = rec.id;
            this.editForm = {};
            const fields = this.formFields[mod] || [];
            for (const h of fields) {
                this.editForm[h] = rec[h] !== undefined ? String(rec[h]) : '';
            }
            this.$nextTick(() => {
                this.$dispatch('oasis:modal-open', { name: 'database-v2-edit', trigger });
            });
        },

        closeEdit() {
            this.$dispatch('oasis:modal-close', { name: 'database-v2-edit', reason: 'cancel' });
        },

        async submitEdit() {
            const form = document.getElementById('database-v2-edit-form');
            const fd = new FormData(form);
            const mod = fd.get('module');
            const id = fd.get('id');
            try {
                const res = await fetch(`${this.baseUrl}/${mod}/${id}`, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: fd,
                });
                const data = await res.json();
                if (!res.ok) { window.oasisToast?.(data.message || 'Gagal menyimpan.', 'error'); return; }
                window.oasisToast?.(data.message || 'Data berhasil diperbarui.', 'success');
                this.closeEdit();
                this.loaded[mod] = false;
                await this.loadModule(mod);
            } catch (e) {
                window.oasisToast?.('Gagal menyimpan. Coba lagi.', 'error');
            }
        },

        async archiveRecord(mod, id) {
            if (!confirm('Arsipkan data ini?')) return;
            try {
                const res = await fetch(`${this.baseUrl}/${mod}/${id}`, {
                    method: 'DELETE',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                });
                const data = await res.json();
                if (!res.ok) { window.oasisToast?.(data.message || 'Gagal mengarsipkan.', 'error'); return; }
                window.oasisToast?.(data.message || 'Data berhasil diarsipkan.', 'success');
                this.loaded[mod] = false;
                await this.loadModule(mod);
            } catch (e) {
                window.oasisToast?.('Gagal mengarsipkan. Coba lagi.', 'error');
            }
        },

        openImport(mod, trigger = null) {
            this.importing = mod;
            this.importRaw = '';
            this.importPreview = null;
            this.importError = '';
            this.$nextTick(() => this.$dispatch('oasis:modal-open', { name: 'database-v2-import', trigger }));
        },

        closeImport() {
            this.$dispatch('oasis:modal-close', { name: 'database-v2-import', reason: 'cancel' });
        },

        backToImportEdit() {
            this.importPreview = null;
            this.importError = '';
        },

        async previewImport() {
            this.importError = '';
            this.importPreview = null;
            if (!this.importing) return;
            try {
                const res = await fetch(`${this.baseUrl}/${this.importing}/import/preview`, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify({ raw: this.importRaw, branch_id: this.branchId }),
                });
                const data = await res.json();
                if (data.error) { this.importError = data.error; return; }
                this.importPreview = data;
            } catch (e) {
                this.importError = 'Data belum dapat diproses. Coba lagi.';
            }
        },

        async saveImportAll() {
            if (!this.importPreview || this.importPreview.valid_count === 0) return;
            await this.doImport(false);
        },

        async saveImportValidOnly() {
            if (!this.importPreview || this.importPreview.valid_count === 0) return;
            await this.doImport(true);
        },

        async doImport(validOnly) {
            try {
                const res = await fetch(`${this.baseUrl}/${this.importing}/import`, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify({ raw: this.importRaw, valid_only: validOnly, branch_id: this.branchId }),
                });
                const data = await res.json().catch(() => ({ message: 'Server tidak merespons dengan JSON. Import gagal.' }));
                if (!res.ok) {
                    this.importError = data.message || `Import gagal (HTTP ${res.status}).`;
                    return;
                }
                this.closeImport();
                window.oasisToast?.(data.message || 'Data berhasil diimpor.', 'success');
                this.loaded[this.importing] = false;
                await this.loadModule(this.importing);
            } catch (e) {
                this.importError = 'Import belum tersimpan. Periksa koneksi dan coba lagi.';
            }
        },

        exportMod(mod) {
            window.open(`${this.baseUrl}/${mod}/export?branch_id=${this.branchId}`, '_blank');
        },

        handleModalClosed(detail) {
            if (detail?.name === 'database-v2-edit') { this.editing = false; this.editingId = null; }
            if (detail?.name === 'database-v2-add') { this.adding = null; }
            if (detail?.name === 'database-v2-import') { this.importing = null; this.importRaw = ''; this.importPreview = null; this.importError = ''; }
        },
    }));
});
</script>
@endpush
