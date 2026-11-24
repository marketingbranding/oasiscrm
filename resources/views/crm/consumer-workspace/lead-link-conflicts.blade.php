@extends('layouts.crm')

@section('title', 'Konflik Penautan Lead - Oasis CRM')

@section('content')
    @php($conflictTypeLabels = ['lead_already_linked' => 'Lead sudah tertaut', 'status' => 'Status keputusan berbeda', 'subject_mismatch' => 'Subjek tidak cocok'])
    <x-crm.page-header variant="canonical" eyebrow="Sales" title="Konflik Penautan Lead" description="Tinjau perbedaan keputusan sumber dan tujuan tanpa menimpa riwayat.">
        <x-slot:actions><a class="crm-button crm-button--secondary" href="{{ route('consumer-database.workspace') }}">Kembali ke Database Konsumen</a></x-slot:actions>
    </x-crm.page-header>
    <div class="crm-table-scroll mt-4">
        <table class="crm-data-table min-w-[900px]">
            <thead><tr><th>ID</th><th>Transaksi</th><th>Jenis Konflik</th><th>Sumber</th><th>Tujuan</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
                @forelse($conflicts as $item)
                    <tr>
                        <td>{{ $item->id }}</td><td>{{ $item->consumer_application_id }}</td>
                        <td>{{ $conflictTypeLabels[$item->conflict_type] ?? 'Konflik penautan' }}</td>
                        <td>{{ $item->source_status ?: '—' }} / Lead {{ $item->source_lead_id ?: '—' }}</td>
                        <td>{{ $item->target_status ?: '—' }} / Lead {{ $item->target_lead_id ?: '—' }}</td>
                        <td>{{ $item->status }}</td>
                        <td><a class="font-bold text-[#0000ee] underline" href="{{ route('consumer-database.workspace.lead-link-conflict.show', $item->id) }}">Tinjau</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-crm.empty-state title="Tidak ada konflik" description="Semua keputusan penautan dalam lingkup Anda konsisten." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
