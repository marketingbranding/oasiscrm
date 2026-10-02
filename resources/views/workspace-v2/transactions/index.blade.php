@extends('workspace-v2.layouts.app')
@section('title', $pageTitle.' | Workspace OASIS')
@section('content')
    @include('workspace-v2.components.page-header', ['eyebrow' => 'PENJUALAN / TRANSAKSI KONSUMEN', 'title' => $pageTitle, 'description' => $pageDescription, 'action' => '<a class="workspace-v2-button workspace-v2-button--primary" href="'.route('workspace-v2.transactions.data-konsumen.create').'">+ Data Konsumen</a>'])
    @php
        $filterValues = [
            'branch_id' => $branches->firstWhere('id', (int) request('branch_id'))?->name,
            'project_id' => $projects->firstWhere('id', (int) request('project_id'))?->project_name,
            'sales_id' => $salesOptions->firstWhere('id', (int) request('sales_id'))?->name,
            'bank' => request('bank'),
            'payment_method' => $paymentOptions[request('payment_method')] ?? null,
            'status' => $statusOptions[request('status')] ?? null,
        ];
        $activeFilters = collect($filterValues)->filter();
        $sortLabels = ['updated' => 'Terakhir diperbarui', 'name' => 'Nama', 'process' => 'Tanggal proses', 'sales' => 'Sales'];
    @endphp
    <form method="GET" class="workspace-v2-toolbar workspace-v2-toolbar--compact" data-filter-form>
        <label class="workspace-v2-search"><span aria-hidden="true">⌕</span><input type="search" name="search" value="{{ $search }}" placeholder="Cari nama, HP, NIK, ID transaksi, kavling..." aria-label="Cari transaksi"></label>
        <details class="workspace-v2-popover">
            <summary class="workspace-v2-button workspace-v2-button--secondary">Filter @if($activeFilters->count())<span class="workspace-v2-count-badge">{{ $activeFilters->count() }}</span>@endif</summary>
            <div class="workspace-v2-popover-panel">
                <div class="workspace-v2-popover-heading"><strong>Filter transaksi</strong><span>Pilih kombinasi yang diperlukan.</span></div>
                <div class="workspace-v2-filter-grid">
                    <label><span>Cabang</span><select name="branch_id"><option value="">Semua cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
                    <label><span>Proyek</span><select name="project_id"><option value="">Semua proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected(request('project_id') == $project->id)>{{ $project->project_name }}</option>@endforeach</select></label>
                    <label><span>Sales</span><select name="sales_id"><option value="">Semua sales</option>@foreach($salesOptions as $sales)<option value="{{ $sales->id }}" @selected(request('sales_id') == $sales->id)>{{ $sales->name }}</option>@endforeach</select></label>
                    <label><span>Bank</span><select name="bank"><option value="">Semua bank</option>@foreach($bankOptions as $bank)<option value="{{ $bank }}" @selected($selectedBank === $bank)>{{ $bank }}</option>@endforeach</select></label>
                    <label><span>Cara Pembayaran</span><select name="payment_method"><option value="">Semua cara pembayaran</option>@foreach($paymentOptions as $value => $label)<option value="{{ $value }}" @selected(request('payment_method') === $value)>{{ $label }}</option>@endforeach</select></label>
                    <label><span>Status</span><select name="status"><option value="">Semua status</option>@foreach($statusOptions as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label>
                </div>
                <div class="workspace-v2-popover-actions"><a href="{{ url()->current() }}" class="workspace-v2-button workspace-v2-button--quiet">Reset</a><button type="submit" class="workspace-v2-button workspace-v2-button--secondary">Terapkan</button></div>
            </div>
        </details>
        <details class="workspace-v2-popover workspace-v2-sort-popover">
            <summary class="workspace-v2-button workspace-v2-button--quiet">Urutkan:<span class="workspace-v2-sort-value">&nbsp;{{ $sortLabels[$selectedSort] }}</span></summary>
            <div class="workspace-v2-popover-panel workspace-v2-sort-panel"><label><span>Urutkan berdasarkan</span><select name="sort"><option value="updated" @selected($selectedSort === 'updated')>Terakhir diperbarui</option><option value="name" @selected($selectedSort === 'name')>Nama</option><option value="process" @selected($selectedSort === 'process')>Tanggal proses</option><option value="sales" @selected($selectedSort === 'sales')>Sales</option></select></label><button type="submit" class="workspace-v2-button workspace-v2-button--secondary">Terapkan</button></div>
        </details>
    </form>
    <div class="workspace-v2-filter-summary"><span>{{ $applications->total() }} transaksi dalam view ini</span>@if($activeFilters->count())<div class="workspace-v2-filter-chips">@foreach($activeFilters as $key => $value)<span class="workspace-v2-filter-chip">{{ $value }} <a href="{{ request()->fullUrlWithQuery([$key => null, 'page' => null]) }}" aria-label="Hapus filter {{ $value }}">×</a></span>@endforeach<a href="{{ url()->current() }}" class="workspace-v2-clear-filters">Hapus semua</a></div>@endif</div>
    @php($processLabels = ['data_konsumen' => 'Data Konsumen', 'PSJB' => 'PSJB', 'psjb' => 'PSJB', 'bi_checking' => 'BI Checking', 'slik' => 'BI Checking', 'pemberkasan' => 'Pemberkasan', 'proses_bank' => 'Proses Bank', 'sp3k' => 'Proses Bank', 'ppjb_dev' => 'PPJB Dev', 'ppjb' => 'PPJB Dev', 'akad' => 'Akad', 'bast' => 'BAST', 'garansi' => 'Garansi', 'selesai' => 'Selesai'])
    <section class="workspace-v2-table-panel" aria-label="Daftar transaksi">
        @if($applications->count())
            <div class="workspace-v2-table-wrap">
                <table class="workspace-v2-table">
                    <thead><tr><th>Nama Konsumen</th><th>ID Transaksi</th><th>Proyek / Kavling</th><th>Sales</th><th>Proses</th><th>Bank / Pembayaran</th><th>Status</th><th>Diperbarui</th><th><span class="sr-only">Aksi</span></th></tr></thead>
                    <tbody>
                    @foreach($applications as $application)
                        @php($bank = $application->bankProcesses->sortByDesc('attempt_no')->first())
                        @php($process = $processLabels[$application->current_process ?: $application->current_stage] ?? \Illuminate\Support\Str::headline((string) ($application->current_process ?: $application->current_stage ?: 'data_konsumen')))
                        <tr class="workspace-v2-table-row" data-detail-url="{{ route('workspace-v2.transaction.detail', $application) }}" tabindex="0">
                            <td><strong>{{ $application->customer?->name ?: $application->nama_konsumen ?: 'Tanpa nama' }}</strong><small>{{ $application->customer?->phone ?: 'No HP belum diisi' }}</small></td>
                            <td class="workspace-v2-mono">{{ $application->id_transaksi }}</td>
                            <td><strong>{{ $application->project?->project_name ?: 'Tanpa proyek' }}</strong><small>{{ $application->kavling?->kavling_code ?: $application->kavling?->name ?: $application->id_kavling ?: 'Kavling belum diisi' }}</small></td>
                            <td>{{ $application->sales?->name ?: 'Belum ditetapkan' }}</td>
                            <td>{{ $process }}</td>
                            <td>{{ $bank?->bank_name ?: ($application->payment_method ? \Illuminate\Support\Str::headline($application->payment_method) : 'Belum diisi') }}</td>
                            <td>@include('workspace-v2.components.status', ['value' => $application->consumer_status ?: $application->transaction_status ?: $application->application_status])</td>
                            <td class="workspace-v2-date">{{ $application->updated_at?->format('d/m/Y H:i') }}</td>
                            <td><a class="workspace-v2-row-action" href="{{ route('workspace-v2.transaction.detail', $application) }}" data-detail-url="{{ route('workspace-v2.transaction.detail', $application) }}">Detail</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="workspace-v2-mobile-cards" aria-label="Daftar transaksi versi mobile">
                @foreach($applications as $application)
                    @php($bank = $application->bankProcesses->sortByDesc('attempt_no')->first())
                    @php($process = $processLabels[$application->current_process ?: $application->current_stage] ?? \Illuminate\Support\Str::headline((string) ($application->current_process ?: $application->current_stage ?: 'data_konsumen')))
                    <article class="workspace-v2-mobile-card" data-detail-url="{{ route('workspace-v2.transaction.detail', $application) }}" tabindex="0" role="button" aria-label="Buka detail {{ $application->customer?->name ?: $application->nama_konsumen ?: 'Tanpa nama' }}">
                        <div><strong>{{ $application->customer?->name ?: $application->nama_konsumen ?: 'Tanpa nama' }}</strong>@include('workspace-v2.components.status', ['value' => $application->consumer_status ?: $application->transaction_status ?: $application->application_status])</div>
                        <span>{{ $application->id_transaksi }}</span>
                        <div><span>{{ $application->kavling?->kavling_code ?: $application->id_kavling ?: 'Kavling belum diisi' }}</span><span>{{ $process }}</span></div>
                        <small>{{ $bank?->bank_name ?: ($application->payment_method ? \Illuminate\Support\Str::headline($application->payment_method) : 'Pembayaran belum diisi') }} · {{ $application->sales?->name ?: 'Sales belum ditetapkan' }}</small>
                    </article>
                @endforeach
            </div>
            <div class="workspace-v2-pagination" data-workspace-pagination>
                <span class="workspace-v2-pagination-summary">{{ $applications->firstItem() }}–{{ $applications->lastItem() }} dari {{ $applications->total() }}</span>
                @if($applications->hasPages())
                    <nav aria-label="Navigasi halaman transaksi">
                        @if($applications->onFirstPage())
                            <span class="workspace-v2-pagination-disabled" aria-disabled="true">Sebelumnya</span>
                        @else
                            <a href="{{ $applications->previousPageUrl() }}" aria-label="Halaman sebelumnya">Sebelumnya</a>
                        @endif
                        @foreach($applications->getUrlRange(1, $applications->lastPage()) as $page => $url)
                            @if($page == $applications->currentPage())
                                <span aria-current="page">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" aria-label="Halaman {{ $page }}">{{ $page }}</a>
                            @endif
                        @endforeach
                        @if($applications->hasMorePages())
                            <a href="{{ $applications->nextPageUrl() }}" aria-label="Halaman berikutnya">Berikutnya</a>
                        @else
                            <span class="workspace-v2-pagination-disabled" aria-disabled="true">Berikutnya</span>
                        @endif
                    </nav>
                @endif
            </div>
        @else
            @include('workspace-v2.components.empty', ['title' => 'Belum ada transaksi pada view ini', 'description' => 'Coba ubah pencarian atau filter, atau mulai dengan mencatat Data Konsumen.'])
        @endif
    </section>
    <aside class="workspace-v2-drawer" data-detail-drawer aria-label="Detail transaksi" aria-hidden="true" hidden>
        <div class="workspace-v2-drawer-header"><div><span class="workspace-v2-eyebrow">DETAIL TRANSAKSI</span><h2 data-detail-title>Memuat...</h2></div><button type="button" class="workspace-v2-icon-button" data-drawer-close aria-label="Tutup detail">×</button></div>
        <div class="workspace-v2-drawer-body" data-detail-body><div class="workspace-v2-drawer-loading">Memuat detail transaksi...</div></div>
    </aside>
@endsection
