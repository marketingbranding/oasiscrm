@extends('layouts.crm')

@section('title', 'Pengeluaran - Oasis CRM')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-2 border-2 border-black bg-[#b3bd95] px-4 py-3">
    <h1 class="font-['Arial_Black'] text-xl font-black uppercase">Pengeluaran</h1>
    <a href="{{ route('expenses.create') }}" class="border-2 border-black bg-white px-4 py-2 font-bold shadow-[2px_2px_0_#000]">+ Tambah Pengeluaran</a>
</div>
<div class="crm-table-scroll">
    <table class="crm-data-table">
        <thead><tr><th>#</th><th>Tanggal</th><th>Cabang</th><th>Proyek</th><th>Kategori</th><th>Deskripsi</th><th>Jumlah</th><th>Status</th><th class="crm-actions">Aksi</th></tr></thead>
        <tbody>
        @forelse($expenses as $expense)
            <tr>
                <td>{{ $expenses->firstItem() + $loop->index }}</td>
                <td>{{ $expense->expense_date->format('d/m/Y') }}</td>
                <td>{{ $expense->branch?->name }}</td>
                <td>{{ $expense->project?->project_name ?? '—' }}</td>
                <td>{{ $expense->category?->name }}</td>
                <td title="{{ $expense->description }}" class="max-w-[18rem] truncate">{{ $expense->description }}</td>
                <td class="whitespace-nowrap font-bold">{{ $expense->formattedAmount() }}</td>
                <td><span class="border border-black px-2 py-1 font-bold {{ $expense->status === \App\Models\Expense::STATUS_CANCELLED ? 'bg-[#d77a7a] text-white' : 'bg-[#b3bd95]' }}">{{ $expense->status === \App\Models\Expense::STATUS_CANCELLED ? 'Dibatalkan' : 'Aktif' }}</span></td>
                <td class="crm-actions whitespace-nowrap"><a href="{{ route('expenses.show', $expense) }}" class="font-bold underline">Lihat</a>@if($expense->status === \App\Models\Expense::STATUS_ACTIVE) <a href="{{ route('expenses.edit', $expense) }}" style="color:#0000ee;font-weight:bold;text-decoration:underline">Edit</a>@endif</td>
            </tr>
        @empty<tr><td colspan="9" class="py-8 text-center italic">Belum ada pengeluaran pada periode ini.</td></tr>@endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $expenses->links() }}</div>
@endsection
