@extends('layouts.crm')

@section('title', 'Pengeluaran - Oasis CRM')

@section('content')
@php
    $currency = fn (float|int $value) => 'Rp'.number_format($value, fmod((float) $value, 1.0) === 0.0 ? 0 : 2, ',', '.');
    $activeFilters = [];
    $selectedProject = $projects->firstWhere('id', $filters['project_id']);
    $filterQueryKeys = ['period_month', 'month', 'year', 'date_from', 'date_to', 'branch_id', 'project_id', 'expense_category_id', 'created_by', 'payment_method', 'status', 'per_page'];

    if (request()->filled('period_month') || request()->filled('month') || request()->filled('year')) {
        $activeFilters[] = 'Periode: '.\Carbon\Carbon::createFromFormat('!Y-m', $filters['period_month'])->locale('id')->translatedFormat('F Y');
    }
    if (request()->filled('date_from') || request()->filled('date_to')) {
        $activeFilters[] = 'Tanggal: '.($filters['date_from'] ?: 'awal').' - '.($filters['date_to'] ?: 'akhir');
    }
    if (request()->filled('branch_id')) {
        $activeFilters[] = 'Cabang: '.($branches->firstWhere('id', $filters['branch_id'])?->name ?? request('branch_id'));
    }
    if (request()->filled('project_id')) {
        $activeFilters[] = 'Proyek: '.($selectedProject ? $selectedProject->project_name.' - '.$selectedProject->branch?->name : request('project_id'));
    }
    if (request()->filled('expense_category_id')) {
        $activeFilters[] = 'Kategori: '.($categories->firstWhere('id', $filters['expense_category_id'])?->name ?? request('expense_category_id'));
    }
    if (request()->filled('created_by')) {
        $activeFilters[] = 'Dibuat oleh: '.($creators->firstWhere('id', $filters['created_by'])?->name ?? request('created_by'));
    }
    if (request()->filled('payment_method')) {
        $activeFilters[] = 'Metode: '.($paymentMethods[$filters['payment_method']] ?? request('payment_method'));
    }
    if (request()->filled('status')) {
        $activeFilters[] = 'Status: '.(['active' => 'Aktif', 'cancelled' => 'Dibatalkan', 'all' => 'Semua Status'][$filters['status']] ?? request('status'));
    }
    if (request()->filled('per_page') && (int) request('per_page') !== 20) {
        $activeFilters[] = 'Per halaman: '.$filters['per_page'];
    }
@endphp
<div x-data="{
    filterOpen: false,
    branch: @js((string) ($filters['branch_id'] ?? '')),
    selectedProject: @js((string) ($filters['project_id'] ?? '')),
    projects: @js($projects->map(fn ($project) => [
        'id' => (string) $project->id,
        'branch_id' => (string) $project->branch_id,
        'name' => $project->project_name,
        'all_label' => $project->project_name.' - '.$project->branch->name,
    ])->values()),
    openFilter() {
        this.filterOpen = true;
        this.$nextTick(() => this.$refs.filterClose.focus());
    },
    closeFilter() {
        this.filterOpen = false;
        this.$nextTick(() => this.$refs.filterButton.focus());
    },
    trapFilterFocus(event) {
        const controls = [...event.currentTarget.querySelectorAll('button, [href], input, select, [tabindex]:not([tabindex=-1])')]
            .filter(control => !control.disabled && control.offsetParent !== null);
        if (controls.length === 0) return;
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    },
}">
<div class="mb-4 flex flex-wrap items-center justify-between gap-2 border-2 border-black bg-[#b3bd95] px-4 py-3">
    <h1 class="font-['Arial_Black'] text-xl font-black uppercase">Pengeluaran</h1>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('expenses.export', request()->query()) }}" class="border-2 border-black bg-[#fef3cd] px-4 py-2 font-bold shadow-[2px_2px_0_#000]">Ekspor XLSX</a>
        <a href="{{ route('expenses.create') }}" class="border-2 border-black bg-white px-4 py-2 font-bold shadow-[2px_2px_0_#000]">+ Tambah Pengeluaran</a>
    </div>
</div>

<div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
    <div class="border-2 border-black bg-white p-3"><div class="font-[Helvetica] text-[10px] font-bold uppercase">Total Aktif Periode</div><div class="mt-1 text-xl font-bold">{{ $currency($summary['total']) }}</div></div>
    <div class="border-2 border-black bg-white p-3"><div class="font-[Helvetica] text-[10px] font-bold uppercase">Transaksi Aktif</div><div class="mt-1 text-xl font-bold">{{ number_format($summary['count'], 0, ',', '.') }}</div><div class="text-xs">Rata-rata {{ $currency($summary['average']) }}</div></div>
    <div class="border-2 border-black bg-white p-3"><div class="font-[Helvetica] text-[10px] font-bold uppercase">Kategori Terbesar</div><div class="mt-1 truncate font-bold" title="{{ $summary['top_category']['label'] ?? '-' }}">{{ $summary['top_category']['label'] ?? '-' }}</div><div class="text-xs">{{ $summary['top_category'] ? $currency($summary['top_category']['total']) : '-' }}</div></div>
    <div class="border-2 border-black bg-white p-3"><div class="font-[Helvetica] text-[10px] font-bold uppercase">Cabang / Proyek Terbesar</div><div class="mt-1 truncate font-bold" title="{{ $summary['top_branch']['label'] ?? '-' }}">{{ $summary['top_branch']['label'] ?? '-' }} · {{ $summary['top_branch'] ? $currency($summary['top_branch']['total']) : '-' }}</div><div class="truncate text-xs" title="{{ $summary['top_project']['label'] ?? '-' }}">Proyek: {{ $summary['top_project']['label'] ?? '-' }} · {{ $summary['top_project'] ? $currency($summary['top_project']['total']) : '-' }}</div></div>
    <div class="border-2 border-black bg-white p-3"><div class="font-[Helvetica] text-[10px] font-bold uppercase">Dibanding Periode Sebelumnya</div><div class="mt-1 text-xl font-bold">{{ $summary['comparison_percent'] === null ? '—' : (($summary['comparison_percent'] >= 0 ? '+' : '').number_format($summary['comparison_percent'], 1, ',', '.').'%') }}</div><div class="text-xs">Sebelumnya {{ $currency($summary['previous_total']) }}</div></div>
</div>

<div class="mb-3 border-2 border-black bg-white p-3">
    <div class="flex flex-wrap items-center gap-2">
        <form method="GET" action="{{ route('expenses.index') }}" class="flex min-w-[240px] max-w-xl grow">
            @foreach(request()->only([...$filterQueryKeys, 'sort', 'dir']) as $key => $value)
                @if($value !== null && $value !== '')<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
            @endforeach
            <input name="search" value="{{ $filters['search'] }}" placeholder="Cari deskripsi, vendor, referensi, atau catatan..." aria-label="Cari pengeluaran" class="min-w-0 grow rounded-none border-2 border-r-0 border-black bg-white px-3 py-1.5 font-['Times_New_Roman'] text-sm">
            <button class="border-2 border-black bg-black px-4 py-1.5 font-[Helvetica] text-sm font-bold text-white">Cari</button>
        </form>
        <button x-ref="filterButton" type="button" @click="openFilter()" class="relative inline-flex items-center gap-2 border-2 border-black bg-white px-4 py-1.5 font-[Helvetica] text-sm font-bold hover:bg-gray-100">
            <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path d="M2.75 3.5a.75.75 0 0 1 .75-.75h13a.75.75 0 0 1 .53 1.28l-5.28 5.28v4.94a.75.75 0 0 1-.36.64l-3 1.8A.75.75 0 0 1 7.25 16V9.31L1.97 4.03a.75.75 0 0 1 .78-1.23v.7Zm2.56.75 3.22 3.22a.75.75 0 0 1 .22.53v6.68l1.5-.9V8a.75.75 0 0 1 .22-.53l3.22-3.22H5.31Z"/></svg>
            Filter
            @if(count($activeFilters) > 0)<span class="inline-flex h-5 min-w-5 items-center justify-center bg-[#c0392b] px-1 text-[10px] text-white">{{ count($activeFilters) }}</span>@endif
        </button>
    </div>
</div>

@if(count($activeFilters) > 0)
<div class="mb-4 flex flex-wrap items-center gap-2">
    <span class="font-[Helvetica] text-[10px] font-bold uppercase">Filter aktif:</span>
    @foreach($activeFilters as $activeFilter)
    <span class="border-2 border-black bg-[#fef3cd] px-2 py-1 font-['Times_New_Roman'] text-xs">{{ $activeFilter }}</span>
    @endforeach
    <a href="{{ route('expenses.index', array_filter(['search' => $filters['search']])) }}" class="text-xs font-bold text-[#c0392b] underline">Hapus semua filter</a>
</div>
@endif

<div x-cloak x-show="filterOpen" role="dialog" aria-modal="true" aria-labelledby="expense-filter-title" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4" @keydown.escape.window="closeFilter()" @keydown.tab="trapFilterFocus($event)">
    <div @click.outside="closeFilter()" class="max-h-[90vh] w-full max-w-3xl overflow-y-auto border-2 border-black bg-white p-5 shadow-[8px_8px_0_0_#000]">
        <div class="mb-4 flex items-center justify-between"><h2 id="expense-filter-title" class="font-[Helvetica] text-sm font-bold uppercase">Filter Pengeluaran</h2><button x-ref="filterClose" type="button" @click="closeFilter()" aria-label="Tutup filter" class="text-lg font-bold">&times;</button></div>
        <form method="GET" action="{{ route('expenses.index') }}" class="space-y-4">
            @if($filters['search'] !== '')<input type="hidden" name="search" value="{{ $filters['search'] }}">@endif
            <input type="hidden" name="sort" value="{{ $filters['sort'] }}"><input type="hidden" name="dir" value="{{ $filters['dir'] }}">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div><label for="expense-filter-period" class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Bulan Laporan</label><div class="month-wrapper" data-accent="#b3bd95"><div class="month-display flex w-full cursor-pointer select-none items-center justify-between border-2 border-black bg-white px-3 py-2 text-sm" tabindex="0"><span class="month-text">— Pilih Bulan —</span><span class="month-arrow">▼</span></div><input id="expense-filter-period" type="month" name="period_month" value="{{ $filters['period_month'] }}" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0"></div></div>
                <div><label for="expense-filter-branch" class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Cabang</label><select id="expense-filter-branch" name="branch_id" x-model="branch" @change="selectedProject = ''" class="w-full border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Semua Cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></div>
                <div><label for="expense-filter-date-from" class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Dari Tanggal (Opsional)</label><div class="date-wrapper" data-accent="#b3bd95"><div class="date-display flex w-full cursor-pointer items-center justify-between border-2 border-black bg-white px-3 py-2 text-sm" tabindex="0"><span class="date-text">— Pilih Tanggal —</span><span class="date-arrow">▼</span></div><input id="expense-filter-date-from" type="date" name="date_from" value="{{ $filters['date_from'] }}" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0"></div></div>
                <div><label for="expense-filter-date-to" class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Sampai Tanggal (Opsional)</label><div class="date-wrapper" data-accent="#b3bd95"><div class="date-display flex w-full cursor-pointer items-center justify-between border-2 border-black bg-white px-3 py-2 text-sm" tabindex="0"><span class="date-text">— Pilih Tanggal —</span><span class="date-arrow">▼</span></div><input id="expense-filter-date-to" type="date" name="date_to" value="{{ $filters['date_to'] }}" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0"></div></div>
                <div><label for="expense-filter-project" class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Proyek</label><select id="expense-filter-project" name="project_id" x-model="selectedProject" class="w-full border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Semua Proyek</option><template x-for="project in projects.filter(item => !branch || item.branch_id === branch)" :key="project.id"><option :value="project.id" x-text="branch ? project.name : project.all_label"></option></template></select><p x-show="branch && projects.filter(item => item.branch_id === branch).length === 0" class="mt-1 text-xs italic">Tidak ada proyek aktif untuk cabang ini.</p></div>
                <div><label for="expense-filter-category" class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Kategori</label><select id="expense-filter-category" name="expense_category_id" class="w-full border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Semua Kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($filters['expense_category_id'] === $category->id)>{{ $category->name }}{{ $category->is_active ? '' : ' (Nonaktif)' }}</option>@endforeach</select></div>
                <div><label for="expense-filter-creator" class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Dibuat Oleh</label><select id="expense-filter-creator" name="created_by" class="w-full border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Semua Pembuat</option>@foreach($creators as $creator)<option value="{{ $creator->id }}" @selected($filters['created_by'] === $creator->id)>{{ $creator->name }}{{ $creator->is_active ? '' : ' (Nonaktif)' }}</option>@endforeach</select></div>
                <div><label for="expense-filter-payment" class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Metode Pembayaran</label><select id="expense-filter-payment" name="payment_method" class="w-full border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Semua Metode</option>@foreach($paymentMethods as $key => $label)<option value="{{ $key }}" @selected($filters['payment_method'] === $key)>{{ $label }}</option>@endforeach</select></div>
                <div><label for="expense-filter-status" class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Status</label><select id="expense-filter-status" name="status" class="w-full border-2 border-black bg-white px-3 py-2 text-sm"><option value="active" @selected($filters['status'] === 'active')>Aktif</option><option value="cancelled" @selected($filters['status'] === 'cancelled')>Dibatalkan</option><option value="all" @selected($filters['status'] === 'all')>Semua Status</option></select></div>
                <div><label for="expense-filter-per-page" class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Per Halaman</label><select id="expense-filter-per-page" name="per_page" class="w-full border-2 border-black bg-white px-3 py-2 text-sm">@foreach([20, 50, 100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>@endforeach</select></div>
            </div>
            <div class="flex flex-wrap gap-2 pt-2"><button class="border-2 border-black bg-black px-6 py-2 font-[Helvetica] text-sm font-bold text-white">Terapkan Filter</button><a href="{{ route('expenses.index', array_filter(['search' => $filters['search']])) }}" class="border-2 border-black bg-white px-6 py-2 font-[Helvetica] text-sm font-bold">Reset Filter</a></div>
        </form>
    </div>
</div>

<div class="crm-table-scroll">
    <table class="crm-data-table">
        <thead><tr>
            <x-crm.click-sort-th field="expense_date" route="expenses.index" label="Tanggal" :current-sort="$filters['sort']" :current-dir="$filters['dir']" />
            <x-crm.click-sort-th field="branch" route="expenses.index" label="Cabang" :current-sort="$filters['sort']" :current-dir="$filters['dir']" />
            <x-crm.click-sort-th field="project" route="expenses.index" label="Proyek" :current-sort="$filters['sort']" :current-dir="$filters['dir']" />
            <x-crm.click-sort-th field="category" route="expenses.index" label="Kategori" :current-sort="$filters['sort']" :current-dir="$filters['dir']" />
            <x-crm.click-sort-th field="description" route="expenses.index" label="Deskripsi" :current-sort="$filters['sort']" :current-dir="$filters['dir']" />
            <x-crm.click-sort-th field="vendor_name" route="expenses.index" label="Vendor" :current-sort="$filters['sort']" :current-dir="$filters['dir']" />
            <x-crm.click-sort-th field="payment_method" route="expenses.index" label="Metode" :current-sort="$filters['sort']" :current-dir="$filters['dir']" />
            <x-crm.click-sort-th field="amount" route="expenses.index" label="Nominal" :current-sort="$filters['sort']" :current-dir="$filters['dir']" align="right" />
            <x-crm.click-sort-th field="created_by" route="expenses.index" label="Dibuat Oleh" :current-sort="$filters['sort']" :current-dir="$filters['dir']" />
            <x-crm.click-sort-th field="status" route="expenses.index" label="Status" :current-sort="$filters['sort']" :current-dir="$filters['dir']" />
            <th class="crm-actions">Aksi</th>
        </tr></thead>
        <tbody>
        @forelse($expenses as $expense)
            <tr>
                <td>{{ $expense->expense_date->format('d/m/Y') }}</td><td>{{ $expense->branch?->name ?? '—' }}</td><td title="{{ $expense->project?->project_name ?? '—' }}">{{ $expense->project?->project_name ?? '—' }}</td><td title="{{ $expense->category?->name ?? '—' }}">{{ $expense->category?->name ?? '—' }}</td>
                <td title="{{ $expense->description }}" class="max-w-[18rem] truncate">{{ $expense->description }}</td><td title="{{ $expense->vendor_name ?? '—' }}">{{ $expense->vendor_name ?? '—' }}</td><td>{{ $paymentMethods[$expense->payment_method] ?? '—' }}</td><td class="text-right font-bold">{{ $expense->formattedAmount() }}</td><td title="{{ $expense->creator?->name ?? '—' }}">{{ $expense->creator?->name ?? '—' }}</td>
                <td><span class="border border-black px-2 py-1 font-bold {{ $expense->status === \App\Models\Expense::STATUS_CANCELLED ? 'bg-[#d77a7a] text-white' : 'bg-[#b3bd95]' }}">{{ $expense->status === \App\Models\Expense::STATUS_CANCELLED ? 'Dibatalkan' : 'Aktif' }}</span></td>
                <td class="crm-actions"><a href="{{ route('expenses.show', $expense) }}" class="font-bold underline">Lihat</a>@if($expense->status === \App\Models\Expense::STATUS_ACTIVE) <a href="{{ route('expenses.edit', $expense) }}" style="color:#0000ee;font-weight:bold;text-decoration:underline">Edit</a>@endif</td>
            </tr>
        @empty<tr><td colspan="11" class="py-8 text-center italic">Belum ada pengeluaran pada periode ini.</td></tr>@endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $expenses->links() }}</div>
</div>
@endsection
