@extends('layouts.crm')
@section('title', 'Laporan Paritas Konsumen - Oasis CRM')
@section('content')
<x-crm.page-header variant="canonical" eyebrow="Diagnostik Superadmin" title="Laporan Paritas Konsumen" description="Perbandingan cermin sheet dan data OASIS per cabang. Tidak ada perubahan data." />
<form method="GET" class="mb-4 grid gap-3 border-2 border-black bg-white p-4 md:grid-cols-3">
    <x-crm.field label="Cabang" for="branch" required>
        <select id="branch" name="branch" class="crm-control" required onchange="this.form.submit()">
            <option value="">Pilih cabang</option>
            @foreach($branches as $item)<option value="{{ $item->id }}" @selected($branch?->id === $item->id)>{{ $item->name }}</option>@endforeach
        </select>
    </x-crm.field>
</form>
@php($report = $snapshot ? new \App\Data\BranchParityReport($branch, $snapshot->status, $snapshot->summary, $snapshot->metrics, $snapshot->differences) : null)
@if($branch)
    <form method="POST" action="{{ route('admin.branch-parity.run', $branch) }}" class="mb-4">@csrf<x-crm.button type="submit" variant="primary">Jalankan pemeriksaan</x-crm.button></form>
    @if(! $report)
        <x-crm.card>Belum pernah diperiksa.</x-crm.card>
    @else
    <div class="mb-4 grid gap-4 md:grid-cols-4">
        <x-crm.card><div class="crm-label">Status</div><strong>{{ $report->status }}</strong></x-crm.card>
        <x-crm.card><div class="crm-label">Sheet only</div><strong>{{ $report->summary['sheet_only'] }}</strong></x-crm.card>
        <x-crm.card><div class="crm-label">OASIS only</div><strong>{{ $report->summary['oasis_only'] }}</strong></x-crm.card>
        <x-crm.card><div class="crm-label">Mismatch</div><strong>{{ $report->summary['mismatch'] }}</strong></x-crm.card>
    </div>
    <x-crm.button href="{{ route('admin.branch-parity.export', $branch) }}" variant="secondary">Unduh XLSX</x-crm.button>
    <x-crm.card class="mt-4"><div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Status</th><th>Kunci Legacy</th><th>ID Aplikasi OASIS</th><th>Kavling</th><th>Kolom Berbeda</th></tr></thead><tbody>
        @forelse($report->differences as $difference)<tr><td>{{ $difference['status'] }}</td><td>{{ $difference['legacy_key'] ?? '—' }}</td><td>{{ $difference['oasis_application_id'] ?? '—' }}</td><td>{{ $difference['kavling'] ?? '—' }}</td><td>{{ implode(', ', $difference['mismatch_fields'] ?? []) ?: '—' }}</td></tr>@empty<tr><td colspan="5">Tidak ada selisih.</td></tr>@endforelse
    </tbody></table></div></x-crm.card>
    @endif
@endif
@endsection
