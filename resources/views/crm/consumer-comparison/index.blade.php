@extends('layouts.crm')
@section('title', 'Perbandingan Data Konsumen - Oasis CRM')
@section('content')
<x-crm.page-header variant="canonical" eyebrow="Diagnostik Superadmin" title="Perbandingan Data Konsumen" description="Baca dan bandingkan snapshot legacy dengan tabel konsumen lokal. Tidak ada perubahan data." />
<form method="GET" class="mb-4 grid gap-3 border-2 border-black bg-white p-4 md:grid-cols-3">
    <x-crm.field label="Cabang" for="branch_id" required>
        <select id="branch_id" name="branch_id" class="crm-control" required onchange="this.form.submit()">
            <option value="">Pilih cabang</option>
            @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($selectedBranch?->id === $branch->id)>{{ $branch->name }}</option>@endforeach
        </select>
    </x-crm.field>
    <x-crm.field label="Proyek" for="project_id" required>
        <select id="project_id" name="project_id" class="crm-control" required {{ $selectedBranch ? '' : 'disabled' }} onchange="this.form.submit()">
            <option value="">Pilih proyek</option>
            @foreach($projects as $project)<option value="{{ $project->id }}" @selected($selectedProject?->id === $project->id)>{{ $project->project_name }}</option>@endforeach
        </select>
    </x-crm.field>
    <div class="self-end text-sm">Sumber legacy: snapshot `KonsumenProgressSheetRow`.<br>Perbandingan hanya baca.</div>
</form>
@if($result)
    <div class="mb-4 grid grid-cols-2 gap-0 border-2 border-black bg-white text-center text-xs font-bold sm:grid-cols-4 lg:grid-cols-7">
        @foreach(['Legacy' => $result->summary['total_legacy'], 'Lokal' => $result->summary['total_local'], 'Cocok' => $result->summary['matched'], 'Berbeda' => $result->summary['mismatch'], 'Hanya Legacy' => $result->summary['legacy_only'], 'Hanya Lokal' => $result->summary['local_only'], 'Perlu Review' => $result->summary['ambiguous']] as $label => $value)
            <div class="border-r border-b border-black p-3">{{ $label }}<br><span class="text-xl">{{ $value }}</span></div>
        @endforeach
    </div>
    <p class="mb-4 text-sm">Cakupan link: {{ $result->coverage['link_coverage_percent'] }}% · Exact match dari data terhubung: {{ $result->coverage['exact_match_percent'] }}%</p>
    <div class="overflow-x-auto border-2 border-black bg-white">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-black text-xs uppercase text-white"><tr><th class="p-2">Nama</th><th class="p-2">Kavling</th><th class="p-2">Tahap Legacy</th><th class="p-2">Tahap Lokal</th><th class="p-2">Status</th><th class="p-2">Perbedaan</th></tr></thead>
            <tbody>
            @forelse($result->rows as $row)
                <tr class="border-b border-gray-300 align-top"><td class="p-2">{{ $row['customer_name'] ?: '—' }}</td><td class="p-2">{{ $row['kavling'] ?: '—' }}</td><td class="p-2">{{ $row['legacy_values']['current_stage'] ?? '—' }}</td><td class="p-2">{{ $row['local_values']['current_stage'] ?? '—' }}</td><td class="p-2 font-bold">{{ $row['status'] }}</td><td class="p-2">{{ $row['mismatch_fields'] ? implode(', ', $row['mismatch_fields']) : ($row['notes'] ? implode('; ', $row['notes']) : '—') }}</td></tr>
            @empty
                <tr><td colspan="6" class="p-6 text-center">Tidak ada data untuk konteks ini.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@else
    <x-crm.empty-state title="Pilih cabang dan proyek" description="Comparison membutuhkan konteks branch dan project agar query tetap terbatas." />
@endif
@endsection

{{-- application_status excluded: legacy snapshot has no proven equivalent semantic. --}}
{{-- Raw source JSON is retained only in server-side result objects and not rendered. --}}
