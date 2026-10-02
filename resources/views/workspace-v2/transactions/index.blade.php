@extends('workspace-v2.layouts.app')
@section('title', $pageTitle.' | Workspace OASIS')
@section('content')
    @include('workspace-v2.components.page-header', ['eyebrow' => 'PENJUALAN / TRANSAKSI KONSUMEN', 'title' => $pageTitle, 'description' => $pageDescription, 'action' => '<a class="workspace-v2-button workspace-v2-button--primary" href="'.route('workspace-v2.transactions.data-konsumen.create').'">+ Data Konsumen</a>'])
    <nav class="workspace-v2-process-strip" aria-label="Process view transaksi">
        @foreach($processViews as $key => $label)
            @if($key === 'semua')
                <a class="{{ $activeProcessView === $key ? 'is-active' : '' }}" href="{{ route('workspace-v2.transactions') }}">{{ $label }}</a>
            @else
                <a class="{{ $activeProcessView === $key ? 'is-active' : '' }}" href="{{ route('workspace-v2.transactions.'.$key) }}">{{ $label }}</a>
            @endif
        @endforeach
    </nav>
    <form method="GET" class="workspace-v2-toolbar" data-filter-form>
        <label class="workspace-v2-search"><span aria-hidden="true">⌕</span><input type="search" name="search" value="{{ $search }}" placeholder="Cari nama, HP, NIK, ID transaksi, kavling..." aria-label="Cari transaksi"></label>
        <select name="branch_id" aria-label="Cabang"><option value="">Semua cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select>
        <select name="status" aria-label="Status"><option value="">Semua status</option>@foreach($statusOptions as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select>
        <select name="project_id" aria-label="Proyek"><option value="">Semua proyek</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected(request('project_id') == $project->id)>{{ $project->project_name }}</option>@endforeach</select>
        <select name="sales_id" aria-label="Sales"><option value="">Semua sales</option>@foreach($salesOptions as $sales)<option value="{{ $sales->id }}" @selected(request('sales_id') == $sales->id)>{{ $sales->name }}</option>@endforeach</select>
        <select name="bank" aria-label="Bank"><option value="">Semua bank</option>@foreach($bankOptions as $bank)<option value="{{ $bank }}" @selected($selectedBank === $bank)>{{ $bank }}</option>@endforeach</select>
        <select name="sort" aria-label="Urutkan"><option value="updated" @selected($selectedSort === 'updated')>Terakhir diperbarui</option><option value="name" @selected($selectedSort === 'name')>Nama</option><option value="process" @selected($selectedSort === 'process')>Tanggal proses</option><option value="sales" @selected($selectedSort === 'sales')>Sales</option></select>
        <button type="submit" class="workspace-v2-button workspace-v2-button--secondary">Terapkan</button>
        @if(request()->hasAny(['search', 'branch_id', 'status', 'project_id', 'sales_id', 'bank', 'sort']))<a class="workspace-v2-button workspace-v2-button--quiet" href="{{ url()->current() }}">Bersihkan</a>@endif
    </form>
    <div class="workspace-v2-filter-hint"><span>{{ $applications->total() }} transaksi dalam view ini</span><span>Urut: terakhir diperbarui</span><button type="button" data-mobile-filter class="workspace-v2-button workspace-v2-button--quiet">Filter lanjutan</button></div>
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
                        <tr class="workspace-v2-mobile-card-row"><td colspan="9"><article class="workspace-v2-mobile-card" data-detail-url="{{ route('workspace-v2.transaction.detail', $application) }}"><div><strong>{{ $application->customer?->name ?: $application->nama_konsumen ?: 'Tanpa nama' }}</strong>@include('workspace-v2.components.status', ['value' => $application->consumer_status ?: $application->transaction_status ?: $application->application_status])</div><span>{{ $application->id_transaksi }}</span><div><span>{{ $application->kavling?->kavling_code ?: $application->id_kavling ?: 'Kavling belum diisi' }}</span><span>{{ $process }}</span></div><small>{{ $bank?->bank_name ?: ($application->payment_method ? \Illuminate\Support\Str::headline($application->payment_method) : 'Pembayaran belum diisi') }} · {{ $application->sales?->name ?: 'Sales belum ditetapkan' }}</small></article></td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="workspace-v2-pagination">{{ $applications->links() }}</div>
        @else
            @include('workspace-v2.components.empty', ['title' => 'Belum ada transaksi pada view ini', 'description' => 'Coba ubah pencarian atau filter, atau mulai dengan mencatat Data Konsumen.'])
        @endif
    </section>
    <aside class="workspace-v2-drawer" data-detail-drawer aria-label="Detail transaksi" aria-hidden="true" hidden>
        <div class="workspace-v2-drawer-header"><div><span class="workspace-v2-eyebrow">DETAIL TRANSAKSI</span><h2 data-detail-title>Memuat...</h2></div><button type="button" class="workspace-v2-icon-button" data-drawer-close aria-label="Tutup detail">×</button></div>
        <div class="workspace-v2-drawer-body" data-detail-body><div class="workspace-v2-drawer-loading">Memuat detail transaksi...</div></div>
    </aside>
@endsection
