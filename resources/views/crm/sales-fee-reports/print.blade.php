<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan Fee Sales - {{ $sales->name }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; color: #000; background: #ddd; font: 9.5pt Arial, sans-serif; }
        .screen-toolbar { width: 210mm; margin: 16px auto 0; }
        .print-sheet { width: 210mm; min-height: 297mm; margin: 16px auto; padding: 12mm; background: #fff; box-shadow: 0 4px 18px rgba(0, 0, 0, .2); }
        h1 { margin: 0 0 2mm; text-align: center; font-size: 15pt; line-height: 1.1; break-after: avoid; page-break-after: avoid; }
        h2 { margin: 3mm 0 1mm; font-size: 10pt; line-height: 1.1; text-transform: uppercase; break-after: avoid; page-break-after: avoid; }
        dl { margin: 0; }
        .metadata { display: grid; grid-template-columns: 27mm 1fr; gap: .5mm 2mm; line-height: 1.15; }
        .metadata-section, .summary-section { break-inside: avoid; page-break-inside: avoid; }
        dt { font-weight: bold; }
        dd { margin: 0; }
        .kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1mm; }
        .kpi { min-height: 12mm; border: 1px solid #000; padding: 1mm; }
        .kpi dd { margin-top: .5mm; font-size: 12pt; line-height: 1; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; font-size: 8pt; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; page-break-inside: avoid; }
        th, td { border: 1px solid #000; padding: 1.1mm; text-align: left; vertical-align: top; white-space: normal; overflow-wrap: anywhere; }
        th { font-size: 8pt; font-weight: bold; }
        .agenda-title { font-weight: bold; }
        .agenda-result { margin-top: .5mm; font-size: 7.5pt; }
        .channel-activity { line-height: 1.2; }
        .empty { text-align: center; }
        .print-button { border: 2px solid #000; padding: 2mm 4mm; color: #000; background: #fff; font-weight: bold; cursor: pointer; }
        @media print {
            html, body { background: #fff; }
            .screen-only, .screen-toolbar, .print-button { display: none !important; }
            .print-sheet { width: 210mm; min-height: auto; margin: 0; padding: 12mm; box-shadow: none; }
        }
    </style>
</head>
<body>
@php($periodLabel = \Illuminate\Support\Carbon::parse($dateFrom)->format('d/m/Y').' - '.\Illuminate\Support\Carbon::parse($dateTo)->format('d/m/Y'))
<div class="screen-toolbar screen-only">
    <button type="button" class="print-button" onclick="window.print()">Cetak</button>
</div>
<main class="print-sheet">
    <header><h1>LAPORAN AKTIVITAS SALES</h1></header>
    <section class="metadata-section" aria-labelledby="metadata-title">
        <h2 id="metadata-title">Informasi Laporan</h2>
        <dl class="metadata"><dt>Sales</dt><dd>{{ $sales->name }}</dd><dt>Koordinator</dt><dd>{{ $coordinator?->name ?? '-' }}</dd><dt>Cabang</dt><dd>{{ $branch->name }}</dd><dt>Proyek</dt><dd>{{ $project->project_name }}</dd><dt>Periode</dt><dd>{{ $periodLabel }}</dd></dl>
    </section>
    <section class="summary-section" aria-labelledby="summary-title">
        <h2 id="summary-title">RINGKASAN</h2>
        <dl class="kpis">@foreach(['total_agenda' => 'Total Agenda', 'agenda_done' => 'Agenda Selesai', 'total_lead' => 'Total Lead', 'face_to_face' => 'Tatap Muka', 'site_visit' => 'Cek Lokasi', 'utj' => 'UTJ'] as $key => $label)<div class="kpi"><dt>{{ $label }}</dt><dd>{{ $metrics[$key] }}</dd></div>@endforeach</dl>
    </section>
    <section aria-labelledby="agenda-title">
        <h2 id="agenda-title">DETAIL AGENDA</h2>
        <table><thead><tr><th>Tanggal</th><th>Kategori</th><th>Agenda / Hasil</th><th>Lokasi</th><th>Status</th></tr></thead><tbody>
            @forelse($agendas as $agenda)<tr><td>{{ $agenda->scheduled_date?->format('d/m/Y') ?? '-' }}</td><td>{{ $agenda->sales_activity_category ?: '-' }}</td><td><div class="agenda-title">{{ $agenda->title }}</div>@if(filled($agenda->activity_result))<div class="agenda-result">Hasil: {{ $agenda->activity_result }}</div>@endif</td><td>{{ $agenda->location ?: '-' }}</td><td>{{ $agendaStatusLabels[$agenda->status] ?? $agenda->status }}</td></tr>@empty<tr><td colspan="5" class="empty">Belum ada agenda pada periode ini.</td></tr>@endforelse
        </tbody></table>
    </section>
    <section aria-labelledby="lead-title">
        <h2 id="lead-title">DETAIL LEAD</h2>
        <table><thead><tr><th>Tanggal</th><th>Konsumen</th><th>Sumber</th><th>Kanal / Aktivitas</th><th>Status</th></tr></thead><tbody>
            @forelse($leads as $lead)<tr><td>{{ $lead->lead_date?->format('d/m/Y') ?? '-' }}</td><td>{{ $lead->customer_name }}</td><td>{{ $lead->effective_source ?: '-' }}</td><td class="channel-activity">{{ collect([$lead->platform, $lead->campaign_name])->filter(fn ($value) => filled($value))->join(' / ') ?: '-' }}</td><td>{{ $lead->current_status?->label() ?? '-' }}</td></tr>@empty<tr><td colspan="5" class="empty">Belum ada lead pada periode ini.</td></tr>@endforelse
        </tbody></table>
    </section>
</main>
</body>
</html>
