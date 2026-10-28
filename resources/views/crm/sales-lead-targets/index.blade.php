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
    <x-crm.section id="sales-lead-target-bulk" title="Terapkan target massal" description="Atur nilai yang sama untuk seluruh Sales aktif lintas cabang pada bulan terpilih.">
        <div class="flex flex-col gap-3 border-2 border-black bg-[#fff8d8] p-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="text-sm">
                <p class="font-bold">{{ $salesUsers->count() }} Sales aktif akan diperbarui.</p>
                <p class="mt-1 text-xs text-gray-700">Target yang sudah ada untuk bulan {{ $month->format('m/Y') }} juga akan ditimpa.</p>
            </div>
            <form id="bulk-sales-target-form" method="POST" action="{{ route('sales-lead-targets.bulk-store') }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                <label class="grid gap-1 text-xs font-bold uppercase">Target semua Sales<input type="number" min="1" max="100000" name="target_leads" value="{{ old('target_leads') }}" class="w-36 border-2 border-black bg-white px-2 py-2" required></label>
                <x-crm.button type="button" variant="primary" accent="sales" @click="$dispatch('oasis:modal-open', { name: 'bulk-sales-target-confirm', trigger: $el })">Terapkan ke semua Sales</x-crm.button>
            </form>
        </div>
    </x-crm.section>
    <x-crm.modal name="bulk-sales-target-confirm" title="Terapkan target ke semua Sales" description="Konfirmasi perubahan target Lead bulanan.">
        <p class="text-sm">Nilai target baru akan diterapkan ke <strong>{{ $salesUsers->count() }} Sales aktif</strong> untuk bulan <strong>{{ $month->format('m/Y') }}</strong>.</p>
        <p class="mt-3 border-2 border-[#d6a500] bg-[#fff8d8] p-3 text-sm font-bold">Target bulan ini yang sudah ada akan ditimpa.</p>
        <x-slot:footer>
            <x-crm.button type="button" variant="secondary" @click="$dispatch('oasis:modal-close', { name: 'bulk-sales-target-confirm' })">Batal</x-crm.button>
            <x-crm.button type="submit" form="bulk-sales-target-form" variant="primary" accent="sales">Terapkan sekarang</x-crm.button>
        </x-slot:footer>
    </x-crm.modal>
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
