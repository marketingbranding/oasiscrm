@extends('layouts.crm')

@section('title', 'Pengeluaran - Oasis CRM')

@section('content')
@php
    $currency = fn (float|int $value) => 'Rp'.number_format($value, 0, ',', '.');
@endphp
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

<form method="GET" action="{{ route('expenses.index') }}" class="mb-4 border-2 border-black bg-white p-4" x-data="{ branch: @js((string) ($filters['branch_id'] ?? '')), projects: @js($projects->map(fn ($project) => ['id' => (string) $project->id, 'branch_id' => (string) $project->branch_id, 'name' => $project->project_name])->values()), selectedProject: @js((string) ($filters['project_id'] ?? '')) }">
    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
        <div>
            <label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Bulan Laporan</label>
            <div class="month-wrapper" data-accent="#b3bd95">
                <div class="month-display flex w-full cursor-pointer select-none items-center justify-between border-2 border-black bg-white px-3 py-2 text-sm" tabindex="0"><span class="month-text">— Pilih Bulan —</span><span class="month-arrow">▼</span></div>
                <input type="month" name="period_month" value="{{ $filters['period_month'] }}" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">
            </div>
        </div>
        <div><label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Dari Tanggal (Opsional)</label><div class="date-wrapper" data-accent="#b3bd95"><div class="date-display flex w-full cursor-pointer items-center justify-between border-2 border-black bg-white px-3 py-2 text-sm" tabindex="0"><span class="date-text">— Pilih Tanggal —</span><span class="date-arrow">▼</span></div><input type="date" name="date_from" value="{{ $filters['date_from'] }}" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0"></div></div>
        <div><label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Sampai Tanggal (Opsional)</label><div class="date-wrapper" data-accent="#b3bd95"><div class="date-display flex w-full cursor-pointer items-center justify-between border-2 border-black bg-white px-3 py-2 text-sm" tabindex="0"><span class="date-text">— Pilih Tanggal —</span><span class="date-arrow">▼</span></div><input type="date" name="date_to" value="{{ $filters['date_to'] }}" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0"></div></div>
        <div><label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Pencarian</label><input name="search" value="{{ $filters['search'] }}" placeholder="Deskripsi, vendor, referensi, catatan" class="w-full border-2 border-black px-3 py-2 text-sm"></div>
        <div><label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Cabang</label><select name="branch_id" x-model="branch" @change="selectedProject = ''" class="w-full border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Semua Cabang</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></div>
        <div><label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Proyek</label><select name="project_id" x-model="selectedProject" class="w-full border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Semua Proyek</option><template x-for="project in projects.filter(item => !branch || item.branch_id === branch)" :key="project.id"><option :value="project.id" x-text="project.name"></option></template></select></div>
        <div><label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Kategori</label><select name="expense_category_id" class="w-full border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Semua Kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($filters['expense_category_id'] === $category->id)>{{ $category->name }}{{ $category->is_active ? '' : ' (Nonaktif)' }}</option>@endforeach</select></div>
        <div><label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Dibuat Oleh</label><select name="created_by" class="w-full border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Semua Pembuat</option>@foreach($creators as $creator)<option value="{{ $creator->id }}" @selected($filters['created_by'] === $creator->id)>{{ $creator->name }}{{ $creator->is_active ? '' : ' (Nonaktif)' }}</option>@endforeach</select></div>
        <div><label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Metode</label><select name="payment_method" class="w-full border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Semua Metode</option>@foreach($paymentMethods as $key => $label)<option value="{{ $key }}" @selected($filters['payment_method'] === $key)>{{ $label }}</option>@endforeach</select></div>
        <div><label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Status</label><select name="status" class="w-full border-2 border-black bg-white px-3 py-2 text-sm"><option value="active" @selected($filters['status'] === 'active')>Aktif</option><option value="cancelled" @selected($filters['status'] === 'cancelled')>Dibatalkan</option><option value="all" @selected($filters['status'] === 'all')>Semua Status</option></select></div>
        <div><label class="mb-1 block font-[Helvetica] text-xs font-bold uppercase">Per Halaman</label><select name="per_page" class="w-full border-2 border-black bg-white px-3 py-2 text-sm">@foreach([20, 50, 100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>@endforeach</select></div>
    </div>
    <input type="hidden" name="sort" value="{{ $filters['sort'] }}"><input type="hidden" name="dir" value="{{ $filters['dir'] }}">
    <div class="mt-4 flex flex-wrap gap-2"><button class="border-2 border-black bg-black px-5 py-2 font-bold text-white">Terapkan Filter</button><a href="{{ route('expenses.index') }}" class="border-2 border-black bg-white px-5 py-2 font-bold">Reset</a></div>
</form>

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
@endsection
