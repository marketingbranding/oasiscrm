@extends('layouts.crm')

@section('title', 'Database Konsumen - Oasis CRM')

@section('content')
    @php
        $activeFilters = collect([
            $showBranchFilter && $selectedBranch ? 'Cabang: '.$selectedBranch->name : null,
            $selectedProject ? 'Proyek: '.$selectedProject->project_name : null,
            $selectedStatus !== '' ? 'Status: '.$selectedStatus : null,
            $selectedLinkStatus !== '' ? 'Penautan: '.($linkStatusOptions[$selectedLinkStatus] ?? $selectedLinkStatus) : null,
            $selectedStage !== '' ? 'Tahap: '.($stageOptions[$selectedStage] ?? $selectedStage) : null,
            $selectedSales ? 'Sales: '.($salesOptions->firstWhere('id', $selectedSales)?->name ?? $selectedSales) : null,
            $selectedBank !== '' ? 'Bank: '.$selectedBank : null,
            $search !== '' ? 'Pencarian: '.$search : null,
        ])->filter()->values();
        $workspaceConfig = [
            'detailUrl' => route('consumer-database.workspace.show', ['consumerApplication' => '__ID__']),
            'leadLinkDecisionUrl' => route('consumer-database.workspace.lead-link-decision.store', ['consumerApplication' => '__ID__']),
             'bulkLegacyPreviewUrl' => route('consumer-database.workspace.bulk-legacy.preview'),
             'bulkLegacyStoreUrl' => route('consumer-database.workspace.bulk-legacy.store'),
             'bulkLegacyBranchId' => $selectedBranch?->id,
             'bulkLegacyProjectId' => $selectedProject?->id,
             'bulkLegacyBefore' => now()->toDateString(),
            'processUrls' => [
                'slik' => route('consumer-process.slik', ['consumerApplication' => '__ID__']),
                'psjb' => route('consumer-process.psjb', ['consumerApplication' => '__ID__']),
                'pemberkasan' => route('consumer-process.pemberkasan', ['consumerApplication' => '__ID__']),
                'bank' => route('consumer-process.bank', ['consumerApplication' => '__ID__']),
                'sp3k' => route('consumer-process.sp3k', ['consumerApplication' => '__ID__']),
                 'ppjb' => route('consumer-process.ppjb', ['consumerApplication' => '__ID__']),
                 'garansi' => route('consumer-process.garansi', ['consumerApplication' => '__ID__']),
                 'kendala' => route('consumer-process.kendala', ['consumerApplication' => '__ID__']),
                 'ready100' => route('consumer-applications.ready100', ['consumer_application' => '__ID__']),
                 'akad' => route('consumer-applications.akad', ['consumer_application' => '__ID__']),
                 'bast' => route('consumer-applications.bast', ['consumer_application' => '__ID__']),
                 'mundur' => route('consumer-applications.mundur', ['consumer_application' => '__ID__']),
                 'pindahKavling' => route('consumer-applications.pindah-kavling', ['consumer_application' => '__ID__']),
                 'gantiBank' => route('consumer-applications.ganti-bank', ['consumer_application' => '__ID__']),
                 'gantiKonsumen' => route('consumer-applications.ganti-konsumen', ['consumer_application' => '__ID__']),
            ],
        ];
        $emptyTitle = $activeFilters->isNotEmpty() ? 'Tidak ada hasil' : 'Belum ada data konsumen';
        $emptyDescription = $activeFilters->isNotEmpty()
            ? 'Tidak ada konsumen yang sesuai dengan pencarian atau filter aktif.'
            : 'Belum ada data konsumen dalam lingkup yang dapat Anda akses.';
    @endphp

    <div x-data="consumerWorkspace(@js($workspaceConfig))">
        <x-crm.page-header
            variant="canonical"
            eyebrow="Sales"
            title="{{ $processTitle ? 'Proses '.($stageOptions[$processTitle] ?? ucfirst(str_replace('_', ' ', $processTitle))) : 'Database Konsumen' }}"
            description="Kelola perjalanan konsumen dari Data Konsumen sampai Form Garansi sesuai cabang dan proyek yang dapat Anda akses."
        >
            <x-slot:actions>
                <x-crm.status-badge variant="info">{{ $applications->total() }} konsumen</x-crm.status-badge>
                @if(auth()->user()->hasPermission('consumer_progress.manage') || auth()->user()->hasScopedPermission('consumer_progress', 'manage'))
                    <a href="{{ route('consumer-database.workspace.create') }}" class="crm-button crm-button--primary">+ Data Konsumen</a>
                @endif
                @if($selectedBranch)
                    <x-crm.status-badge variant="neutral">{{ $selectedBranch->name }}</x-crm.status-badge>
                @endif
            </x-slot:actions>
        </x-crm.page-header>

        <x-crm.page-presence page-key="consumer-database-workspace" :branch-id="$selectedBranch?->id" />

        @if($linkSubjects->isNotEmpty())
            <section class="mb-4 border-2 border-black bg-white p-3" aria-labelledby="lead-link-subjects-title">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="lead-link-subjects-title" class="font-bold">Penautan Lead — Sheet dan NUP</h2>
                    <span class="crm-type-label">{{ $linkSubjectPaginator->total() }} subjek</span>
                </div>
                <div class="mt-2 flex flex-wrap gap-2 text-xs">
                    @foreach($linkStatusOptions as $value => $label)
                        <a href="{{ request()->fullUrlWithQuery(['link_status' => $value, 'link_page' => 1]) }}" class="crm-filter-chip">{{ $label }}: {{ $linkSubjectTotals[$value] }}</a>
                    @endforeach
                </div>
                <div class="mt-2 overflow-x-auto">
                    <table class="crm-table min-w-full">
                        <thead><tr><th>Sumber</th><th>Nama</th><th>Status data</th><th>Aksi</th></tr></thead>
                        <tbody>
                            @foreach($linkSubjects as $subject)
                                <tr>
                                    <td>{{ $subject['source'] === 'sheet' ? 'Sheet' : 'NUP' }}</td>
                                    <td>{{ $subject['name'] ?: 'Tanpa nama' }}</td>
                                    <td>{{ $subject['decidable'] ? 'Belum diputuskan' : ($subject['note'] ?: 'Tidak dapat diputuskan') }}</td>
                                    <td>
                                        @if($subject['decidable'])
                                            <a class="font-bold text-[#0000ee] underline" href="{{ route('consumer-database.workspace.link-subject.show', ['subjectType' => $subject['source'], 'subjectId' => $subject['subject_id']]) }}">Buka</a>
                                        @else
                                            <span class="text-gray-600">ID kosong</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($linkSubjectPaginator->hasPages())
                    <div class="mt-3">{{ $linkSubjectPaginator->links() }}</div>
                @endif
            </section>
        @endif

        @if(auth()->user()->hasPermission('consumer_progress.manage') && $linkSubjects->isNotEmpty())
            <section class="mb-4" aria-labelledby="bulk-legacy-title" x-data="{ bulkOpen: false }">
                <button type="button" class="crm-button crm-button--secondary" x-on:click="bulkOpen = !bulkOpen" :aria-expanded="bulkOpen.toString()">Tandai Data Lama (massal)</button>
                <div x-show="bulkOpen" x-cloak class="mt-3 border-2 border-black bg-[#fff8cf] p-3" role="region" aria-labelledby="bulk-legacy-title">
                    <h2 id="bulk-legacy-title" class="font-sans font-bold">Tandai Data Lama</h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="bulk-legacy-branch" class="crm-type-label font-sans">Cabang <span aria-hidden="true">*</span></label>
                            <select id="bulk-legacy-branch" class="crm-control font-sans" x-model="bulkLegacyBranchId" required>
                                <option value="">Pilih cabang</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="bulk-legacy-project" class="crm-type-label font-sans">Proyek</label>
                            <select id="bulk-legacy-project" class="crm-control font-sans" x-model="bulkLegacyProjectId">
                                <option value="">Semua proyek</option>
                                @foreach($projects as $project)
                                    <option value="{{ $project->id }}">{{ $project->project_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="bulk-legacy-before" class="crm-type-label font-sans">Masuk sebelum tanggal <span aria-hidden="true">*</span></label>
                            <input id="bulk-legacy-before" type="date" class="crm-control font-sans" x-model="bulkLegacyBefore" required>
                        </div>
                        <div>
                            <label for="bulk-legacy-reason" class="crm-type-label font-sans">Alasan <span aria-hidden="true">*</span></label>
                            <input id="bulk-legacy-reason" type="text" class="crm-control font-sans" placeholder="Alasan wajib" x-model="bulkLegacyReason" required>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <button type="button" class="crm-button crm-button--secondary font-sans" x-on:click="previewBulkLegacy()">Pratinjau</button>
                        <button type="button" class="crm-button crm-button--danger font-sans" x-on:click="submitBulkLegacy(bulkLegacyReason)" :disabled="!bulkLegacyResult?.token || !bulkLegacyReason.trim()">Konfirmasi</button>
                        <a class="crm-button crm-button--secondary font-sans" href="{{ route('consumer-database.workspace.lead-link-conflicts') }}">Daftar konflik</a>
                    </div>
                    <p class="mt-2 text-sm font-sans text-red-700" x-text="bulkLegacyError"></p>
                    <template x-if="bulkLegacyResult?.token">
                        <div class="mt-3 border-t border-black pt-3 font-sans" role="status">
                            <p x-text="`Tandai ${bulkLegacyResult.count} konsumen sebagai Data Lama?`"></p>
                            <ul class="mt-2 list-disc pl-5 text-sm"><template x-for="example in bulkLegacyResult.examples" :key="example.subject_key"><li x-text="`${example.name || 'Tanpa nama'} — ${example.source}`"></li></template></ul>
                        </div>
                    </template>
                    <template x-if="bulkLegacySummary">
                        <p class="mt-3 border-t border-black pt-3 font-sans text-sm" role="status" x-text="`Selesai — dibuat: ${bulkLegacySummary.created}, dilewati: ${bulkLegacySummary.skipped}, ID kosong: ${bulkLegacySummary.empty_id}`"></p>
                    </template>
                </div>
            </section>
        @endif

        <x-crm.toolbar label="Pencarian dan filter Database Konsumen" class="mb-3">
            <form method="GET" action="{{ route('consumer-database.workspace') }}" class="flex min-w-0 flex-1 flex-wrap items-end gap-2">
                <div class="min-w-[220px] flex-1">
                    <label for="consumer-workspace-search" class="crm-type-label">Cari konsumen</label>
                    <input id="consumer-workspace-search" type="search" name="search" value="{{ $search }}" placeholder="Nama, no HP, NIK 16 digit, transaksi, atau kavling" class="crm-control w-full">
                </div>
                @if($showBranchFilter)
                    <div class="w-full sm:w-auto">
                    <label for="consumer-workspace-branch" class="crm-type-label">Cabang</label>
                    <select id="consumer-workspace-branch" name="branch_id" class="crm-control">
                        <option value="">Semua cabang</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected($selectedBranch?->id === $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    </div>
                @endif
                <div class="w-full sm:w-auto">
                    <label for="consumer-workspace-project" class="crm-type-label">Proyek</label>
                    <select id="consumer-workspace-project" name="project_id" class="crm-control">
                        <option value="">Semua proyek</option>
                        @foreach($projects as $project)
                            <option value="{{ $project->id }}" @selected($selectedProject?->id === $project->id)>{{ $project->project_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full sm:w-auto">
                    <label for="consumer-workspace-status" class="crm-type-label">Status</label>
                    <select id="consumer-workspace-status" name="status" class="crm-control">
                        <option value="">Semua status</option>
                        @foreach($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($selectedStatus === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full sm:w-auto">
                    <label for="consumer-workspace-link-status" class="crm-type-label">Status penautan Lead</label>
                    <select id="consumer-workspace-link-status" name="link_status" class="crm-control">
                        <option value="">Semua status penautan</option>
                        @foreach($linkStatusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($selectedLinkStatus === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full sm:w-auto">
                    <label for="consumer-workspace-stage" class="crm-type-label">Tahap</label>
                    <select id="consumer-workspace-stage" name="stage" class="crm-control">
                        <option value="">Semua tahap</option>
                        @foreach($stageOptions as $value => $label)
                            <option value="{{ $value }}" @selected($selectedStage === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full sm:w-auto">
                    <label for="consumer-workspace-sales" class="crm-type-label">Sales</label>
                    <select id="consumer-workspace-sales" name="sales_id" class="crm-control">
                        <option value="">Semua Sales</option>
                        @foreach($salesOptions as $sales)
                            <option value="{{ $sales->id }}" @selected($selectedSales === $sales->id)>{{ $sales->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-full sm:w-auto">
                    <label for="consumer-workspace-bank" class="crm-type-label">Bank</label>
                    <select id="consumer-workspace-bank" name="bank" class="crm-control">
                        <option value="">Semua bank</option>
                        @foreach($bankOptions as $bank)
                            <option value="{{ $bank }}" @selected($selectedBank === $bank)>{{ $bank }}</option>
                        @endforeach
                    </select>
                </div>
                <x-crm.button type="submit" accent="consumer-progress" variant="primary">Terapkan</x-crm.button>
                @if($activeFilters->isNotEmpty())
                    <a href="{{ route('consumer-database.workspace') }}" class="crm-button crm-button--secondary">Reset</a>
                @endif
            </form>
        </x-crm.toolbar>

        @if($activeFilters->isNotEmpty())
            <div class="mb-4 flex flex-wrap items-center gap-2" aria-label="Filter aktif">
                <span class="crm-type-label">Filter aktif:</span>
                @foreach($activeFilters as $activeFilter)
                    <x-crm.filter-chip :label="$activeFilter" />
                @endforeach
                <a href="{{ route('consumer-database.workspace') }}" class="text-xs font-bold underline">Hapus semua filter</a>
            </div>
        @endif

        <div class="mb-3 flex items-center justify-between gap-3">
            <div>
                <h2 class="font-[Helvetica] text-sm font-bold uppercase">Daftar konsumen</h2>
                <p class="font-sans text-sm text-gray-600">Buka detail untuk melihat proses, pembayaran, riwayat, dan tindakan operasional.</p>
            </div>
            <span class="text-xs text-gray-600">{{ $applications->firstItem() ?? 0 }}–{{ $applications->lastItem() ?? 0 }} dari {{ $applications->total() }}</span>
        </div>

        <div class="hidden crm-table-scroll md:block">
            <table class="crm-data-table min-w-[920px]">
                <caption class="sr-only">Daftar Database Konsumen</caption>
                <thead>
                    <tr>
                        <th scope="col" class="crm-row-num">No</th>
                        <th scope="col">Nama Konsumen</th>
                        <th scope="col">Proyek</th>
                        <th scope="col">Kavling</th>
                        <th scope="col">Sales</th>
                        <th scope="col">Tahap</th>
                        <th scope="col">Bank Saat Ini</th>
                        <th scope="col">Status</th>
                        <th scope="col">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($applications as $application)
                        @php
                            $stage = $stageOptions[$application->current_process ?: $application->current_stage] ?? $stageOptions[$application->current_stage] ?? $application->current_process ?: $application->current_stage;
                            $status = $application->transaction_status ?: ($application->consumer_status ?: $application->application_status);
                            $statusLabel = $statusOptions[$status] ?? $status;
                            $bank = $application->bankProcesses->sortByDesc('attempt_no')->first()?->bank_name;
                            $kavling = $application->kavling?->kavling_code ?: $application->kavling?->name ?: $application->id_kavling;
                        @endphp
                        <tr>
                            <td class="crm-row-num">{{ $applications->firstItem() + $loop->index }}</td>
                            <td>
                                <button type="button" class="font-bold text-[#0000ee] underline" @click="openDetail({{ $application->id }}, $el)">{{ $application->customer?->name ?: $application->nama_konsumen ?: 'Tanpa nama' }}</button>
                                <div class="text-xs text-gray-600">{{ $application->id_transaksi }}</div>
                            </td>
                            <td title="{{ $application->project?->project_name }}">{{ $application->project?->project_name ?: '—' }}</td>
                            <td>{{ $kavling ?: '—' }}</td>
                            <td>{{ $application->sales?->name ?: '—' }}</td>
                            <td>{{ $stage ?: 'Belum ditentukan' }}</td>
                            <td>{{ $bank ?: 'Belum ada' }}</td>
                            <td><x-crm.status-badge variant="{{ in_array($status, ['Mundur', 'REPLACED'], true) ? 'danger' : ($status === 'Lanjut' || $status === 'active' ? 'success' : 'neutral') }}">{{ $statusLabel ?: 'Belum ditentukan' }}</x-crm.status-badge></td>
                            <td><button type="button" class="font-bold text-[#0000ee] underline" @click="openDetail({{ $application->id }}, $el)">Buka detail</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><x-crm.empty-state :title="$emptyTitle" :description="$emptyDescription" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="grid gap-3 md:hidden">
            @forelse($applications as $application)
                @php
                            $stage = $stageOptions[$application->current_process ?: $application->current_stage] ?? $stageOptions[$application->current_stage] ?? $application->current_process ?: $application->current_stage;
                            $status = $application->transaction_status ?: ($application->consumer_status ?: $application->application_status);
                    $statusLabel = $statusOptions[$status] ?? $status;
                    $bank = $application->bankProcesses->sortByDesc('attempt_no')->first()?->bank_name;
                    $kavling = $application->kavling?->kavling_code ?: $application->kavling?->name ?: $application->id_kavling;
                @endphp
                <article class="border-2 border-black bg-white p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="truncate font-bold">{{ $application->customer?->name ?: $application->nama_konsumen ?: 'Tanpa nama' }}</h2>
                            <p class="truncate text-xs text-gray-600">{{ $application->id_transaksi }}</p>
                        </div>
                        <x-crm.status-badge variant="{{ in_array($status, ['Mundur', 'REPLACED'], true) ? 'danger' : ($status === 'Lanjut' || $status === 'active' ? 'success' : 'neutral') }}">{{ $statusLabel ?: 'Belum ditentukan' }}</x-crm.status-badge>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        <div><dt class="crm-type-label">Proyek</dt><dd>{{ $application->project?->project_name ?: '—' }}</dd></div>
                        <div><dt class="crm-type-label">Kavling</dt><dd>{{ $kavling ?: '—' }}</dd></div>
                        <div><dt class="crm-type-label">Tahap</dt><dd>{{ $stage ?: 'Belum ditentukan' }}</dd></div>
                        <div><dt class="crm-type-label">Bank</dt><dd>{{ $bank ?: 'Belum ada' }}</dd></div>
                        <div><dt class="crm-type-label">Sales</dt><dd>{{ $application->sales?->name ?: '—' }}</dd></div>
                    </dl>
                    <button type="button" class="mt-3 w-full border-2 border-black bg-white px-3 py-2 text-sm font-bold" @click="openDetail({{ $application->id }}, $el)">Buka detail</button>
                </article>
            @empty
                <x-crm.empty-state :title="$emptyTitle" :description="$emptyDescription" />
            @endforelse
        </div>

        @if($applications->hasPages())
            <div class="mt-4">{{ $applications->links() }}</div>
        @endif

        <div x-show="open" x-cloak class="crm-modal-backdrop crm-modal-backdrop--right" role="presentation" @click.self="closeDetail()" @keydown="handleKeydown($event)">
            <section x-ref="drawer" role="dialog" aria-modal="true" aria-labelledby="consumer-drawer-title" tabindex="-1" class="crm-modal-panel crm-modal-panel--lg crm-modal-panel--right">
                <header class="crm-modal-header">
                    <div class="min-w-0">
                        <p class="crm-type-label">Database Konsumen</p>
                        <h2 id="consumer-drawer-title" class="crm-modal-title" x-text="detail?.overview?.customer_name || 'Detail Konsumen'"></h2>
                    </div>
                    <x-crm.icon-button label="Tutup detail konsumen" x-ref="drawerClose" @click="closeDetail()">
                        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg>
                    </x-crm.icon-button>
                </header>
                <div class="crm-modal-body">
                    <div x-show="loading" role="status" class="crm-loading-state"><span class="crm-loading-spinner" aria-hidden="true"></span><span>Memuat detail konsumen...</span></div>
                    <div x-show="error" x-cloak role="alert" class="crm-alert crm-alert--error"><div><strong>Detail belum dapat dimuat</strong><p x-text="error"></p></div><div class="crm-alert-actions"><x-crm.button type="button" size="sm" @click="retry()">Coba Lagi</x-crm.button></div></div>
                    <template x-if="detail && !loading && !error">
                        <div>
                            <nav class="mb-4 flex gap-1 overflow-x-auto border-b-2 border-black" aria-label="Bagian detail konsumen" role="tablist">
                                 @foreach(['overview' => 'Ringkasan', 'lead_link' => 'Penautan Lead', 'process' => 'Proses', 'payment' => 'Pembayaran', 'actions' => 'Tindakan', 'files' => 'Berkas', 'activity' => 'Riwayat', 'comments' => 'Komentar'] as $tab => $label)
                                    <button id="consumer-{{ $tab }}-tab" type="button" role="tab" class="shrink-0 border-2 border-b-0 border-black px-3 py-2 text-xs font-bold uppercase" :class="activeTab === '{{ $tab }}' ? 'bg-[#fcc20f]' : 'bg-white'" @click="activeTab = '{{ $tab }}'" :aria-selected="activeTab === '{{ $tab }}'" aria-controls="consumer-{{ $tab }}-panel">{{ $label }}</button>
                                @endforeach
                            </nav>
                            <section id="consumer-overview-panel" x-show="activeTab === 'overview'" role="tabpanel" aria-labelledby="consumer-overview-tab">
                                <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <template x-for="field in [
                                        ['Transaksi Konsumen', detail.overview.id_transaksi], ['Cabang', detail.overview.branch], ['Proyek', detail.overview.project], ['Kavling', detail.overview.kavling],
                                        ['Sales', detail.overview.sales], ['No. HP', detail.overview.phone], ['Proses Saat Ini', detail.overview.current_stage], ['Bank Saat Ini', detail.overview.bank_current],
                                        ['Pengajuan Bank', detail.overview.bank_attempts ? detail.overview.bank_attempts + ' percobaan' : 'Belum ada'], ['Status Aplikasi', detail.overview.application_status],
                                        ['Status Konsumen', detail.overview.consumer_status], ['Tanggal Booking', formatDate(detail.overview.booking_date)], ['Tanggal Akad', formatDate(detail.overview.akad_date)], ['Terakhir Diperbarui', formatDateTime(detail.overview.updated_at)]
                                    ]" :key="field[0]">
                                        <div class="border-b border-gray-300 pb-2"><dt class="crm-type-label" x-text="field[0]"></dt><dd class="mt-1 text-sm" x-text="formatValue(field[1])"></dd></div>
                                     </template>
                                 </dl>
                             </section>
                             <section id="consumer-actions-panel" x-show="activeTab === 'actions'" x-cloak role="tabpanel" aria-labelledby="consumer-actions-tab" class="grid gap-3">
                                 <div class="border-2 border-black bg-[#fff9df] p-3 text-sm"><strong x-text="detail.overview.customer_name"></strong><p class="mt-1 text-gray-700"><span x-text="detail.overview.id_transaksi"></span> · <span x-text="detail.overview.project"></span> · <span x-text="detail.overview.kavling || 'Kavling belum dipilih'"></span></p><p class="mt-1 text-xs text-gray-600">Input hanya fakta baru. Identitas, Sales, Proyek, dan transaksi dibawa otomatis.</p></div>
                                 <details class="border border-gray-400 p-3"><summary class="cursor-pointer font-bold">Catat SLIK</summary><form method="POST" :action="processUrl('slik')" class="mt-3 grid gap-2 sm:grid-cols-2">@csrf<input name="tanggal_slik" type="date" required class="crm-control" aria-label="Tanggal SLIK"><input name="hasil_slik" required placeholder="Hasil SLIK" class="crm-control"><input name="keputusan" placeholder="Keputusan" class="crm-control"><input name="keterangan" placeholder="Keterangan" class="crm-control"><button class="crm-button crm-button--primary sm:col-span-2">Simpan SLIK</button></form></details>
                                 <details class="border border-gray-400 p-3"><summary class="cursor-pointer font-bold">Catat PSJB</summary><form method="POST" :action="processUrl('psjb')" class="mt-3 grid gap-2 sm:grid-cols-2">@csrf<input name="tanggal_psjb" type="date" required class="crm-control" aria-label="Tanggal PSJB"><input name="harga_unit" type="number" step="0.01" placeholder="Harga Unit" class="crm-control"><input name="tanggal_utj" type="date" aria-label="Tanggal UTJ" class="crm-control"><input name="utj" type="number" step="0.01" placeholder="Nominal UTJ" class="crm-control"><input name="tanggal_dp_klt" type="date" aria-label="Tanggal DP KLT" class="crm-control"><input name="dp_all_in" type="number" step="0.01" placeholder="DP All In" class="crm-control"><input name="nominal_cicilan" type="number" step="0.01" placeholder="Nominal Cicilan" class="crm-control"><input name="jumlah_cicilan" type="number" placeholder="Jumlah Cicilan" class="crm-control"><input name="luas_klt" type="number" step="0.01" placeholder="Luas KLT" class="crm-control"><input name="harga_klt_m" type="number" step="0.01" placeholder="Harga KLT/m" class="crm-control"><input name="harga_klt_total" type="number" step="0.01" placeholder="Harga KLT Total" class="crm-control"><input name="cara_pembayaran" placeholder="Cara Pembayaran" class="crm-control"><input name="nama_promo" placeholder="Nama Promo" class="crm-control"><button class="crm-button crm-button--primary sm:col-span-2">Simpan PSJB</button></form></details>
                                 <details class="border border-gray-400 p-3"><summary class="cursor-pointer font-bold">Catat Pemberkasan</summary><form method="POST" :action="processUrl('pemberkasan')" class="mt-3 grid gap-2 sm:grid-cols-2">@csrf<input name="tanggal_terima_bank" type="date" aria-label="Tanggal Terima Bank" class="crm-control"><input name="bank_name" required placeholder="Bank" class="crm-control"><input name="kc_unit" placeholder="KC / Unit" class="crm-control"><input name="request_plafond" type="number" step="0.01" placeholder="Request Plafond" class="crm-control"><input name="request_tenor" type="number" placeholder="Request Tenor" class="crm-control"><input name="tipe_pemberkasan" placeholder="Tipe Pemberkasan" class="crm-control"><button class="crm-button crm-button--primary sm:col-span-2">Simpan Pemberkasan</button></form></details>
                                 <details class="border border-gray-400 p-3"><summary class="cursor-pointer font-bold">Perbarui Proses Bank</summary><form method="POST" :action="processUrl('bank')" class="mt-3 grid gap-2 sm:grid-cols-2">@csrf<input name="bank_name" required placeholder="Bank aktif" class="crm-control"><input name="response_type" placeholder="Jenis Respon" class="crm-control"><input name="approved_plafond" type="number" step="0.01" placeholder="Approved Plafond" class="crm-control"><input name="approved_tenor" type="number" placeholder="Approved Tenor" class="crm-control"><input name="revision_category" placeholder="Kategori Revisi" class="crm-control"><textarea name="revision_detail" placeholder="Detail Revisi" class="crm-control sm:col-span-2"></textarea><textarea name="obstacle" placeholder="Kendala" class="crm-control sm:col-span-2"></textarea><button class="crm-button crm-button--primary sm:col-span-2">Simpan Proses Bank</button></form></details>
                                 <details class="border border-gray-400 p-3"><summary class="cursor-pointer font-bold">Catat SP3K</summary><form method="POST" :action="processUrl('sp3k')" class="mt-3 grid gap-2 sm:grid-cols-2">@csrf<input name="no_sp3k" required placeholder="No. SP3K" class="crm-control"><input name="sp3k_at" type="date" required aria-label="Tanggal SP3K" class="crm-control"><input name="approved_plafond" type="number" step="0.01" placeholder="Approved Plafond" class="crm-control"><input name="approved_tenor" type="number" placeholder="Approved Tenor" class="crm-control"><textarea name="notes" placeholder="Catatan" class="crm-control sm:col-span-2"></textarea><button class="crm-button crm-button--primary sm:col-span-2">Simpan SP3K</button></form></details>
                                  <details class="border border-gray-400 p-3"><summary class="cursor-pointer font-bold">Catat PPJB</summary><form method="POST" :action="processUrl('ppjb')" class="mt-3 grid gap-2 sm:grid-cols-2">@csrf<input name="tanggal_ttd_ppjb" type="date" required aria-label="Tanggal TTD PPJB" class="crm-control"><input name="notes" placeholder="Catatan" class="crm-control"><button class="crm-button crm-button--primary sm:col-span-2">Simpan PPJB</button></form></details>
                                  <details class="border border-gray-400 p-3"><summary class="cursor-pointer font-bold">Catat Ready100</summary><form method="POST" :action="processUrl('ready100')" class="mt-3 grid gap-2 sm:grid-cols-2">@csrf<input name="ready_100_at" type="date" required aria-label="Tanggal Ready100" class="crm-control"><input name="status" placeholder="Status" class="crm-control"><textarea name="notes" placeholder="Catatan" class="crm-control sm:col-span-2"></textarea><button class="crm-button crm-button--primary sm:col-span-2">Simpan Ready100</button></form></details>
                                  <details class="border border-gray-400 p-3"><summary class="cursor-pointer font-bold">Catat Akad</summary><form method="POST" :action="processUrl('akad')" class="mt-3 grid gap-2 sm:grid-cols-2">@csrf<input name="tanggal_akad" type="date" required aria-label="Tanggal Akad" class="crm-control"><input name="no_ppjb_akad" placeholder="No. PPJB / Akad" class="crm-control"><input name="kualitas_akad" placeholder="Kualitas Akad" class="crm-control"><input name="status_bangunan" placeholder="Status Bangunan" class="crm-control"><input name="status_dp_konsumen" placeholder="Status DP Konsumen" class="crm-control"><input name="status_utilitas" placeholder="Status Utilitas" class="crm-control"><input name="status_konsumen" placeholder="Status Konsumen" class="crm-control"><textarea name="keterangan_terlambat" placeholder="Keterangan terlambat" class="crm-control sm:col-span-2"></textarea><button class="crm-button crm-button--primary sm:col-span-2">Simpan Akad</button></form></details>
                                  <details class="border border-gray-400 p-3"><summary class="cursor-pointer font-bold">Catat BAST</summary><form method="POST" :action="processUrl('bast')" class="mt-3 grid gap-2 sm:grid-cols-2">@csrf<input name="tanggal_bast" type="date" required aria-label="Tanggal BAST" class="crm-control"><input name="no_bast" placeholder="No. BAST" class="crm-control"><input name="status" placeholder="Status" class="crm-control"><textarea name="notes" placeholder="Catatan" class="crm-control sm:col-span-2"></textarea><button class="crm-button crm-button--primary sm:col-span-2">Simpan BAST</button></form></details>
                                  <details class="border border-gray-400 p-3"><summary class="cursor-pointer font-bold">Form Garansi</summary><form method="POST" :action="processUrl('garansi')" class="mt-3 grid gap-2 sm:grid-cols-2">@csrf<select name="status_komplain" required class="crm-control"><option>Belum Dipilih</option><option>Ada Komplain</option><option>Tidak Ada Komplain</option></select><select name="status_garansi" required class="crm-control"><option>Belum Dipilih</option><option>Proses</option><option>Tidak Ada Komplain</option><option>Selesai</option></select><input name="tgl_sales_ke_sam" type="date" aria-label="Tanggal Sales ke SAM" class="crm-control"><input name="tgl_sam_ke_sat" type="date" aria-label="Tanggal SAM ke SAT" class="crm-control"><input name="tgl_sat_ke_sam" type="date" aria-label="Tanggal SAT ke SAM" class="crm-control"><input name="tgl_sam_ke_sales" type="date" aria-label="Tanggal SAM ke Sales" class="crm-control"><input name="tgl_sales_ke_kons" type="date" aria-label="Tanggal Sales ke Konsumen" class="crm-control"><input name="tanggal_selesai" type="date" aria-label="Tanggal Selesai" class="crm-control"><textarea name="detail_garansi" placeholder="Detail Garansi" class="crm-control sm:col-span-2"></textarea><button class="crm-button crm-button--primary sm:col-span-2">Simpan Form Garansi</button></form></details>
                                  <details class="border border-gray-400 p-3"><summary class="cursor-pointer font-bold">Catat Kendala</summary><form method="POST" :action="processUrl('kendala')" class="mt-3 grid gap-2 sm:grid-cols-2">@csrf<select name="process_key" required class="crm-control"><option value="proses_bank">Proses Bank</option><option value="akad">Akad</option><option value="bast">BAST</option><option value="garansi">Garansi</option><option value="other">Proses lain</option></select><input name="category" placeholder="Kategori" class="crm-control"><textarea name="description" required placeholder="Deskripsi kendala" class="crm-control sm:col-span-2"></textarea><button class="crm-button crm-button--primary sm:col-span-2">Simpan Kendala</button></form></details>
                                  <details class="border border-gray-400 p-3"><summary class="cursor-pointer font-bold">Tindakan Khusus</summary><div class="mt-3 grid gap-3"><form method="POST" :action="processUrl('mundur')">@csrf<button class="crm-button crm-button--danger w-full" onclick="return confirm('Tandai transaksi ini sebagai Mundur?')">Tandai Mundur</button></form><form method="POST" :action="processUrl('pindahKavling')" class="grid gap-2 sm:grid-cols-[1fr_auto]">@csrf<input name="target_kavling_id" type="number" required placeholder="ID Kavling tujuan" class="crm-control"><button class="crm-button crm-button--secondary">Pindah Kavling</button></form><form method="POST" :action="processUrl('gantiBank')" class="grid gap-2 sm:grid-cols-[1fr_auto]">@csrf<input name="bank_name" required placeholder="Bank baru" class="crm-control"><button class="crm-button crm-button--secondary">Ganti Bank</button></form><form method="POST" :action="processUrl('gantiKonsumen')" class="grid gap-2 sm:grid-cols-[1fr_auto]">@csrf<input name="replacement_customer_id" type="number" required placeholder="ID Konsumen pengganti" class="crm-control"><button class="crm-button crm-button--secondary">Ganti Konsumen</button></form></div></details>
                              </section>
                            <section id="consumer-lead_link-panel" x-show="activeTab === 'lead_link'" x-cloak role="tabpanel" aria-labelledby="consumer-lead_link-tab" class="grid gap-3">
                                 <div class="border-2 border-black bg-[#fff9df] p-3 text-sm">
                                     <h3 class="font-bold">Penautan Lead</h3>
                                     <p class="mt-1 text-gray-700">Pilih sumber identitas konsumen untuk pencatatan yang ter-audit. ID transaksi tidak berubah.</p>
                                 </div>
                                 <div x-show="leadLinkError" x-cloak role="alert" class="crm-alert crm-alert--error"><div><strong>Keputusan belum tersimpan</strong><p x-text="leadLinkError"></p></div></div>
                                 <div x-show="leadLinkSuccess" x-cloak role="status" class="crm-alert crm-alert--success"><p x-text="leadLinkSuccess"></p></div>
                                 <form x-show="detail.lead_link.allowed_actions.can_decide" @submit.prevent="submitLeadLinkDecision()" class="grid gap-3 border-2 border-black bg-white p-3">
                                     <fieldset class="grid gap-2">
                                         <legend class="crm-type-label">Sumber identitas konsumen</legend>
                                         <label class="flex items-start gap-2"><input type="radio" value="linked" x-model="leadLink.status" class="mt-1" required><span><strong>Lead</strong><span class="block text-xs text-gray-600">Tautkan konsumen ke Lead terpilih.</span></span></label>
                                         <label class="flex items-start gap-2"><input type="radio" value="without_lead" x-model="leadLink.status" class="mt-1"><span><strong>Tanpa Lead</strong><span class="block text-xs text-gray-600">Gunakan bila Lead tidak ditemukan atau tidak dapat dipastikan.</span></span></label>
                                         <label class="flex items-start gap-2"><input type="radio" value="legacy" x-model="leadLink.status" class="mt-1"><span><strong>Data Lama</strong><span class="block text-xs text-gray-600">Tandai data yang sudah ada sebelum penautan Lead.</span></span></label>
                                     </fieldset>
                                     <div x-show="leadLink.status === 'linked'" x-cloak>
                                         <label for="consumer-lead-link-lead" class="crm-type-label">Lead</label>
                                         <select id="consumer-lead-link-lead" x-model="leadLink.sales_lead_id" class="crm-control mt-1 w-full" :required="leadLink.status === 'linked'"><option value="">Pilih Lead</option><template x-for="suggestion in detail.lead_link.suggestions" :key="suggestion.lead_id"><option :value="suggestion.lead_id" x-text="suggestion.lead_name + (suggestion.sales_name ? ' · ' + suggestion.sales_name : '')"></option></template></select>
                                     </div>
                                     <div x-show="leadLink.status === 'legacy'" x-cloak><label for="consumer-lead-link-anchor" class="crm-type-label">Jangkar Data Lama</label><select id="consumer-lead-link-anchor" x-model="leadLink.legacy_anchor" class="crm-control mt-1 w-full" :required="leadLink.status === 'legacy'"><option value="">Pilih jangkar</option><option value="[SYS] ID Konsumen">[SYS] ID Konsumen</option><option value="ID Konsumen Awal">ID Konsumen Awal</option></select></div>
                                     <div><label for="consumer-lead-link-reason" class="crm-type-label">Alasan</label><textarea id="consumer-lead-link-reason" x-model.trim="leadLink.reason" rows="3" maxlength="2000" class="crm-control mt-1 w-full" :required="leadLink.status !== 'linked'" placeholder="Tuliskan alasan keputusan"></textarea></div>
                                     <button type="submit" class="crm-button crm-button--primary w-full" :disabled="leadLinkSaving" x-text="leadLinkSaving ? 'Menyimpan...' : 'Simpan Keputusan'">Simpan Keputusan</button>
                                 </form>
                                 <div x-show="!detail.lead_link.allowed_actions.can_decide" class="border border-gray-400 bg-gray-50 p-3 text-sm text-gray-600">Anda hanya memiliki akses lihat untuk keputusan penautan Lead.</div>
                                 <section class="border border-gray-400 p-3"><h3 class="font-bold">Keputusan Saat Ini</h3><p x-show="!detail.lead_link.current" class="mt-2 text-sm text-gray-600">Belum ada keputusan.</p><dl x-show="detail.lead_link.current" class="mt-2 grid gap-2 text-sm"><div><dt class="crm-type-label">Status</dt><dd x-text="leadLinkStatusLabel(detail.lead_link.current?.status)"></dd></div><div><dt class="crm-type-label">Lead</dt><dd x-text="detail.lead_link.current?.lead_name || '—'"></dd></div><div><dt class="crm-type-label">Alasan</dt><dd x-text="detail.lead_link.current?.reason || '—'"></dd></div></dl></section>
                                 <section class="border border-gray-400 p-3"><h3 class="font-bold">Riwayat koreksi</h3><p x-show="detail.lead_link.history.length === 0" class="mt-2 text-sm text-gray-600">Belum ada riwayat koreksi.</p><ol x-show="detail.lead_link.history.length > 0" class="mt-2 grid gap-2"><template x-for="item in detail.lead_link.history" :key="item.id"><li class="border-b border-gray-300 pb-2 text-sm"><strong x-text="leadLinkStatusLabel(item.status)"></strong><span class="ml-2 text-gray-600" x-text="formatDateTime(item.decided_at)"></span><p x-show="item.reason" class="mt-1" x-text="item.reason"></p><p x-show="item.legacy_anchor" class="mt-1 text-xs text-gray-600" x-text="item.legacy_anchor"></p></li></template></ol></section>
                             </section>
                             <section id="consumer-process-panel" x-show="activeTab === 'process'" x-cloak role="tabpanel" aria-labelledby="consumer-process-tab">
                                 <template x-if="detail.process.length > 0"><ol class="grid gap-3" aria-label="Linimasa proses konsumen"><template x-for="event in detail.process" :key="event.stage + event.occurred_at"><li class="border border-gray-400 p-3"><div class="flex items-center justify-between gap-2"><strong x-text="event.stage || 'Tahap proses'"></strong><span class="text-xs text-gray-600" x-text="formatDate(event.event_date || event.occurred_at)"></span></div><p x-show="event.summary" class="mt-1 text-sm font-bold" x-text="event.summary"></p><p x-show="event.status || event.decision" class="mt-1 text-sm" x-text="formatValue(event.status || event.decision)"></p><p x-show="event.notes" class="mt-1 text-sm text-gray-600" x-text="event.notes"></p></li></template></ol></template>
                                 <p x-show="detail.process.length === 0" class="text-sm text-gray-600">Belum ada riwayat proses lokal.</p>
                                 <div x-show="detail.warranty.length > 0" class="mt-4 grid gap-2"><h3 class="font-bold">Riwayat Garansi</h3><template x-for="warranty in detail.warranty" :key="warranty.status_garansi + warranty.tanggal_selesai"><article class="border border-gray-400 p-3 text-sm"><strong x-text="warranty.status_garansi || 'Belum Dipilih'"></strong><span class="ml-2 text-gray-600" x-text="warranty.tanggal_selesai ? formatDate(warranty.tanggal_selesai) : 'Belum selesai'"></span><p x-show="warranty.detail_garansi" class="mt-1 text-gray-600" x-text="warranty.detail_garansi"></p></article></template></div>
                                 <div x-show="detail.issues.length > 0" class="mt-4 grid gap-2"><h3 class="font-bold">Kendala</h3><template x-for="issue in detail.issues" :key="issue.process + issue.opened_at"><article class="border border-gray-400 p-3 text-sm"><strong x-text="issue.process"></strong><span class="ml-2 text-gray-600" x-text="issue.status"></span><p class="mt-1" x-text="issue.description"></p><p x-show="issue.resolution" class="mt-1 text-gray-600" x-text="issue.resolution"></p></article></template></div>
                             </section>
                            <section id="consumer-payment-panel" x-show="activeTab === 'payment'" x-cloak role="tabpanel" aria-labelledby="consumer-payment-tab">
                                <template x-if="detail.payment.length > 0"><div class="grid gap-3"><template x-for="attempt in detail.payment" :key="attempt.attempt_no + (attempt.bank_name || '')"><article class="border border-gray-400 p-3"><div class="flex items-center justify-between gap-2"><strong x-text="'Percobaan ' + attempt.attempt_no + ' · ' + (attempt.bank_name || 'Bank belum dipilih')"></strong><span class="text-xs font-bold" x-text="formatValue(attempt.status || attempt.response_type)"></span></div><p class="mt-1 text-sm" x-text="attempt.sp3k_at ? 'SP3K: ' + formatDate(attempt.sp3k_at) : (attempt.rejected_at ? 'Ditolak: ' + formatDate(attempt.rejected_at) : 'Belum ada keputusan akhir')"></p></article></template></div></template>
                                <p x-show="detail.payment.length === 0" class="text-sm text-gray-600">Belum ada percobaan bank lokal.</p>
                            </section>
                            <section id="consumer-files-panel" x-show="activeTab === 'files'" x-cloak role="tabpanel" aria-labelledby="consumer-files-tab" class="border border-gray-400 p-4 text-sm text-gray-600">Belum ada tampilan berkas pada fase ini. Jumlah berkas tersimpan: <strong x-text="detail.counts.files"></strong>.</section>
                            <section id="consumer-activity-panel" x-show="activeTab === 'activity'" x-cloak role="tabpanel" aria-labelledby="consumer-activity-tab">
                                <template x-if="detail.activity.length > 0"><ol class="grid gap-3" aria-label="Riwayat aktivitas konsumen"><template x-for="item in detail.activity" :key="item.created_at + item.description"><li class="border border-gray-400 p-3"><div class="flex items-center justify-between gap-2"><strong x-text="item.description"></strong><span class="text-xs text-gray-600" x-text="formatDateTime(item.created_at)"></span></div><p x-show="item.source" class="mt-1 text-xs text-gray-600" x-text="item.source"></p></li></template></ol></template>
                                <p x-show="detail.activity.length === 0" class="text-sm text-gray-600">Belum ada riwayat aktivitas.</p>
                            </section>
                            <section id="consumer-comments-panel" x-show="activeTab === 'comments'" x-cloak role="tabpanel" aria-labelledby="consumer-comments-tab" class="border-2 border-dashed border-gray-400 p-4 text-sm text-gray-600">Komentar konsumen belum tersedia pada fase ini.</section>
                        </div>
                    </template>
                </div>
            </section>
        </div>
    </div>
@endsection
