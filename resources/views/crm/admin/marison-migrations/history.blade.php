@extends('layouts.crm')
@section('title', 'Riwayat Migrasi Marison')
@section('content')
<x-crm.page-header variant="canonical" eyebrow="Administrasi" title="Riwayat Migrasi Marison" description="Batch preview dan impor paket Marison V2."><x-slot:actions><x-crm.button variant="primary" :href="route('admin.marison-migrations.create')">Unggah Paket</x-crm.button></x-slot:actions></x-crm.page-header>
<div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Waktu</th><th>Cabang</th><th>Versi</th><th>Status</th><th>Transaksi</th><th>Aksi</th></tr></thead><tbody>@forelse($batches as $batch)<tr><td>{{ $batch->created_at?->format('d/m/Y H:i') }}</td><td>{{ $batch->source_branch_code }}</td><td>{{ $batch->source_version }}</td><td>{{ $batch->status }}</td><td>{{ data_get($batch->counts, 'transactions', 0) }}</td><td><x-crm.button variant="text" :href="route('admin.marison-migrations.batches.show', $batch)">Lihat</x-crm.button></td></tr>@empty<tr><td colspan="6">Belum ada batch.</td></tr>@endforelse</tbody></table></div><x-crm.pagination :collection="$batches" />
@endsection
