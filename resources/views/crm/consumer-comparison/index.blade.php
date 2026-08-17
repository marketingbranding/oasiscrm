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
    @php
        $statusClass = ['NOT_READY' => 'bg-red-100 text-red-900', 'REVIEW' => 'bg-yellow-100 text-yellow-900', 'PILOT_CANDIDATE' => 'bg-green-100 text-green-900'][$readiness['status']];
    @endphp
    <section class="mb-4 border-2 border-black bg-white p-4" aria-labelledby="readiness-heading">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
            <div><p class="text-xs font-bold uppercase tracking-wide">Pilot readiness</p><h2 id="readiness-heading" class="text-lg font-bold">{{ $readiness['status'] }}</h2><p class="text-sm text-gray-700">Status ini hanya panduan pilot terbatas, bukan cutover produksi.</p></div>
            <span class="px-3 py-2 text-xs font-bold {{ $statusClass }}">{{ $readiness['status'] === 'NOT_READY' ? 'Belum cukup untuk pilot' : ($readiness['status'] === 'REVIEW' ? 'Perlu review manual' : 'Kandidat pilot terbatas') }}</span>
        </div>
        <div class="grid grid-cols-2 gap-3 text-sm md:grid-cols-4"><div>Coverage link<br><strong>{{ $readiness['link_coverage_percent'] }}%</strong></div><div>Exact match<br><strong>{{ $readiness['exact_match_percent'] }}%</strong></div><div>Identity ambiguity<br><strong>{{ $readiness['ambiguous'] }}</strong></div><div>Mismatches<br><strong>{{ $readiness['mismatch'] }}</strong></div></div>
        <ul class="mt-3 list-disc pl-5 text-sm">@foreach($readiness['recommendations'] as $recommendation)<li>{{ $recommendation }}</li>@endforeach</ul>
        <div class="mt-3 text-xs text-gray-700">Mismatch field: @foreach($readiness['field_mismatches'] as $field => $count) @if($count > 0)<span class="mr-2">{{ $field }} ({{ $count }})</span>@endif @endforeach</div>
        <p class="mt-3 text-xs text-gray-600">Coverage rendah dapat berasal dari identity compatibility Phase 2. Tidak ada fuzzy matching atau auto-link.</p>
    </section>
    <div class="mb-4 grid grid-cols-2 gap-0 border-2 border-black bg-white text-center text-xs font-bold sm:grid-cols-4 lg:grid-cols-7">
        @foreach(['Legacy' => $result->summary['total_legacy'], 'Lokal' => $result->summary['total_local'], 'Cocok' => $result->summary['matched'], 'Berbeda' => $result->summary['mismatch'], 'Hanya Legacy' => $result->summary['legacy_only'], 'Hanya Lokal' => $result->summary['local_only'], 'Perlu Review' => $result->summary['ambiguous']] as $label => $value)
            <div class="border-r border-b border-black p-3">{{ $label }}<br><span class="text-xl">{{ $value }}</span></div>
        @endforeach
    </div>
    <section class="mb-4 border-2 border-black bg-white p-4" aria-labelledby="identity-audit-heading">
        <h2 id="identity-audit-heading" class="text-lg font-bold">Phase 5.6 — Consumer Identity Bridge Audit</h2>
        <p class="mb-3 text-sm text-gray-700">Audit baca-saja. Tidak membuat atau mengubah identity mapping.</p>
        <div class="grid grid-cols-2 gap-3 text-sm md:grid-cols-4"><div>Legacy rows<br><strong>{{ $audit['legacy']['total'] }}</strong></div><div>Local applications<br><strong>{{ $audit['local']['total'] }}</strong></div><div>Unique phone+kavling<br><strong>{{ $audit['candidates']['UNIQUE_PHONE_KAVLING'] }}</strong></div><div>Ambiguous<br><strong>{{ $audit['candidates']['AMBIGUOUS'] }}</strong></div></div>
        <div class="mt-3 grid gap-3 text-sm md:grid-cols-2"><div><strong>Legacy identity fields</strong><br>{{ collect($audit['legacy']['counts'])->except(['phone', 'kavling', 'status', 'nik'])->map(fn ($v, $k) => $k.': '.$v)->implode(' · ') }}<br>Phone {{ $audit['legacy']['counts']['phone'] }} · Kavling {{ $audit['legacy']['counts']['kavling'] }} · NIK tersedia {{ $audit['legacy']['counts']['nik'] }}</div><div><strong>Application status</strong><br>{{ collect($audit['local']['application_status'])->map(fn ($v, $k) => $k.': '.$v)->implode(' · ') }}<br><strong>Identity prefix</strong><br>{{ collect($audit['local']['identity_prefixes'])->map(fn ($v, $k) => $k.': '.$v)->implode(' · ') }}</div></div>
        <div class="mt-3 grid gap-3 text-sm md:grid-cols-3"><div><strong>Consumer status</strong><br>{{ collect($audit['local']['consumer_status'])->map(fn ($v, $k) => $k.': '.$v)->implode(' · ') }}</div><div><strong>Last process</strong><br>{{ collect($audit['local']['source_last_process'])->map(fn ($v, $k) => $k.': '.$v)->implode(' · ') }}</div><div><strong>Completeness source</strong><br>{{ collect($audit['local']['source_completeness_status'])->map(fn ($v, $k) => $k.': '.$v)->implode(' · ') }}</div></div>
        <div class="mt-3 text-sm"><strong>Semantic enrichment coverage</strong>: at least one {{ $audit['local']['semantic_coverage']['at_least_one'] }} · all three {{ $audit['local']['semantic_coverage']['all_three'] }} · none {{ $audit['local']['semantic_coverage']['none'] }}</div>
        <div class="mt-3 grid gap-3 text-sm md:grid-cols-3"><div>Legacy duplicate phone<br><strong>{{ $audit['legacy']['duplicates']['phone'] }}</strong></div><div>Legacy duplicate kavling<br><strong>{{ $audit['legacy']['duplicates']['kavling'] }}</strong></div><div>Local duplicate phone+kavling<br><strong>{{ $audit['local']['duplicates']['phone_kavling'] }}</strong></div></div>
        <div class="mt-3 overflow-x-auto"><table class="min-w-full text-left text-xs"><thead class="bg-black text-white"><tr><th class="p-2">Nama</th><th class="p-2">Phone</th><th class="p-2">Kavling</th><th class="p-2">Consumer status</th><th class="p-2">Kategori</th></tr></thead><tbody>@foreach($audit['candidates']['rows'] as $row)<tr class="border-b border-gray-300"><td class="p-2">{{ $row['name'] ?: '—' }}</td><td class="p-2">{{ $row['phone'] }}</td><td class="p-2">{{ $row['kavling'] ?: '—' }}</td><td class="p-2">{{ $row['consumer_status'] }}</td><td class="p-2 font-bold">{{ $row['category'] }}</td></tr>@endforeach</tbody></table></div>
    </section>
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
