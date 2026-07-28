@extends('layouts.crm')
@section('title', 'Riwayat Import Pengguna - Oasis CRM')
@section('content')
<x-crm.page-header color="#8c9ae0" title="Riwayat Import Pengguna" />
<div class="mb-4 flex flex-wrap gap-2 border-2 border-black bg-white p-3">
    <a href="{{ route('admin-users.import') }}" class="border-2 border-black bg-[#8c9ae0] px-4 py-2 text-xs font-bold">IMPORT BARU</a>
    <a href="{{ route('admin-users.import-template') }}" class="border-2 border-black bg-white px-4 py-2 text-xs font-bold">UNDUH TEMPLATE</a>
</div>
<div class="crm-table-scroll"><table class="crm-data-table"><thead><tr><th>Waktu</th><th>File</th><th>Pengunggah</th><th>Status</th><th>Total</th><th>Valid</th><th>Peringatan</th><th>Error</th><th>Aksi</th></tr></thead>
<tbody>@forelse($batches as $batch)<tr><td>{{ $batch->created_at->format('d/m/Y H:i') }}</td><td>{{ $batch->original_filename }}</td><td>{{ $batch->uploader?->name ?? '-' }}</td><td>{{ strtoupper($batch->status) }}</td><td>{{ $batch->total_rows }}</td><td>{{ $batch->valid_rows }}</td><td>{{ $batch->warning_rows }}</td><td>{{ $batch->error_rows }}</td><td><a href="{{ route('admin-users.import-batches.show', $batch) }}" class="font-bold text-[#0000ee] underline">Lihat</a></td></tr>@empty<tr><td colspan="9" class="text-center">Belum ada riwayat import pengguna.</td></tr>@endforelse</tbody></table></div>
<div class="mt-4">{{ $batches->links() }}</div>
@endsection
