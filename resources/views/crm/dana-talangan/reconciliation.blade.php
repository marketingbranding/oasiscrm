@extends('layouts.crm')

@section('title', 'Rekonsiliasi Dana Talangan - Oasis CRM')

@section('content')
<x-crm.page-header
    variant="canonical"
    title="Rekonsiliasi Dana Talangan"
    eyebrow="Operasional"
    description="Tinjau konflik workbook tanpa menampilkan data pribadi konsumen."
>
    <x-slot:actions>
        <x-crm.button :href="route('dana-talangan.index')" variant="secondary">Kembali</x-crm.button>
    </x-slot:actions>
</x-crm.page-header>

<div class="crm-table-scroll">
    <table class="crm-data-table">
        <thead>
            <tr>
                <th>Baris Remote</th>
                <th>Masalah</th>
                <th>Field</th>
                <th>Status</th>
                <th class="crm-actions">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
            <tr>
                <td>{{ $item->remote_row_number ?? '—' }}</td>
                <td>{{ str_replace('_', ' ', $item->issue_code) }}</td>
                <td>{{ $item->field_names ? implode(', ', $item->field_names) : '—' }}</td>
                <td><x-crm.status-badge variant="warning">PERLU TINJAUAN</x-crm.status-badge></td>
                <td class="crm-actions">
                    @if($item->issue_code === 'remote_create_pending_review')
                    <form method="POST" action="{{ route('dana-talangan.reconciliation.approve', $item) }}">
                        @csrf
                        <button class="font-[Helvetica] text-xs font-bold underline text-[#0000ee]">Setujui Baris</button>
                    </form>
                    @else
                    —
                    @endif
                </td>
            </tr>
            @empty
            <tr><td colspan="5"><x-crm.empty-state title="Tidak ada rekonsiliasi terbuka." description="Workbook dan data OASIS tidak memiliki konflik terbuka." /></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-crm.pagination :collection="$items" />
@endsection
