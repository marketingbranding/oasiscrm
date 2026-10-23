@extends('layouts.crm')

@section('title', 'Target Lead Sales - Oasis CRM')

@section('content')
<div class="space-y-4">
    <x-crm.page-header variant="canonical" eyebrow="Administrasi Buku Saku Sales" title="Target Lead Bulanan" description="Target ditetapkan per Sales dan per bulan. Actual dihitung berdasarkan PIC Sales pada lead." />
    @if(session('success'))
        <x-crm.alert variant="success" title="Berhasil">{{ session('success') }}</x-crm.alert>
    @endif
    <x-crm.section id="sales-lead-target-period" title="Periode target" description="Pilih bulan untuk melihat actual dan menetapkan target anggota Sales.">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <label class="grid gap-1 text-xs font-bold uppercase">Bulan<input type="month" name="month" value="{{ $month->format('Y-m') }}" class="border-2 border-black bg-white px-3 py-2"></label>
            <x-crm.button type="submit" variant="secondary">Tampilkan</x-crm.button>
        </form>
    </x-crm.section>
    <div class="crm-table-scroll">
        <table class="crm-data-table min-w-[760px]">
            <thead><tr><th>Sales</th><th>Actual Lead</th><th>Target</th><th>Pencapaian</th><th>Aksi</th></tr></thead>
            <tbody>
                @forelse($salesUsers as $sales)
                    @php
                        $summary = $summaries[$sales->id];
                    @endphp
                    <tr>
                        <td class="font-bold">{{ $sales->name }}</td>
                        <td>{{ $summary['actual'] }}</td>
                        <td>{{ $summary['target'] ?? 'Belum ditetapkan' }}</td>
                        <td>{{ $summary['achievement'] === null ? '—' : number_format($summary['achievement'], 1, ',', '.').'%' }}</td>
                        <td>
                            <form method="POST" action="{{ route('sales-lead-targets.store') }}" class="flex min-w-[260px] items-end gap-2">
                                @csrf
                                <input type="hidden" name="sales_user_id" value="{{ $sales->id }}">
                                <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                                <label class="grid gap-1 text-xs font-bold uppercase">Target<input type="number" min="1" max="100000" name="target_leads" value="{{ old('sales_user_id') == $sales->id ? old('target_leads') : ($summary['target'] ?? '') }}" class="w-28 border-2 border-black px-2 py-2" required></label>
                                <x-crm.button type="submit" variant="primary" accent="sales" size="sm">Simpan</x-crm.button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">Belum ada Sales aktif.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
