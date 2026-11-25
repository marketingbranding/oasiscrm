@extends('layouts.crm')

@section('title', 'Laporan Dana Talangan')

@section('content')
<x-crm.page-header variant="canonical" eyebrow="Laporan" title="Laporan Dana Talangan" description="Rekap nominal dan pembayaran berdasarkan scope serta periode." />

<form method="GET" action="{{ route('dana-talangan.report') }}">
    <x-crm.toolbar label="Filter laporan Dana Talangan">
        <x-crm.field label="Cabang" for="branch_id">
            <select id="branch_id" name="branch_id" class="crm-control">
                <option value="">Semua cabang</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected($branchId === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </x-crm.field>
        <x-crm.field label="Proyek" for="project_id">
            <select id="project_id" name="project_id" class="crm-control">
                <option value="">Semua proyek</option>
                @foreach($projects as $project)
                    <option value="{{ $project->id }}" @selected($projectId === $project->id)>{{ $project->project_name }}</option>
                @endforeach
            </select>
        </x-crm.field>
        <x-crm.field label="Tanggal talangan mulai" for="from">
            <x-crm.date-field id="from" name="from" :value="$from->toDateString()" required />
        </x-crm.field>
        <x-crm.field label="Tanggal talangan selesai" for="to">
            <x-crm.date-field id="to" name="to" :value="$to->toDateString()" required />
        </x-crm.field>
        <x-slot:actions>
            <x-crm.button variant="secondary" :href="route('dana-talangan.report.export', request()->query())">Export Excel</x-crm.button>
            <x-crm.button type="submit" variant="primary" accent="reports">Terapkan Filter</x-crm.button>
        </x-slot:actions>
    </x-crm.toolbar>
</form>

<x-crm.section id="dana-talangan-report-summary" title="Ringkasan" description="Pembayaran diterima dalam periode dipisahkan dari total pembayaran sampai akhir periode.">
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach(['count' => 'Jumlah Talangan', 'nominal' => 'Total Nominal', 'verified' => 'Terverifikasi s.d. akhir periode', 'unverified' => 'Belum Terverifikasi s.d. akhir periode', 'received_verified' => 'Terverifikasi diterima dalam periode', 'received_unverified' => 'Belum terverifikasi diterima dalam periode', 'remaining' => 'Sisa', 'overpaid' => 'Lebih Bayar'] as $key => $label)
            <x-crm.card><div class="text-xs">{{ $label }}</div><div class="text-lg font-semibold">{{ in_array($key, ['nominal', 'verified', 'unverified', 'received_verified', 'received_unverified', 'remaining', 'overpaid'], true) ? \App\Support\DanaTalanganMoney::format($report[$key]) : number_format($report[$key]) }}</div></x-crm.card>
        @endforeach
    </div>
</x-crm.section>

<x-crm.section id="dana-talangan-report-status" title="Jumlah per Status">
    <div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Status</th><th>Jumlah</th></tr></thead><tbody>
        @forelse($report['by_status'] as $status => $count)
            <tr><td>{{ $status }}</td><td>{{ $count }}</td></tr>
        @empty
            <tr><td colspan="2"><x-crm.empty-state title="Tidak ada data pada periode ini." /></td></tr>
        @endforelse
    </tbody></table></div>
</x-crm.section>
@endsection
