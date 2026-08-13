<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan Fee Sales - {{ $sales->name }}</title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #000; background: #fff; font: 11pt Arial, sans-serif; }
        main { width: 100%; }
        h1 { margin: 0 0 4mm; text-align: center; font-size: 18pt; }
        h2 { margin: 6mm 0 2mm; font-size: 12pt; text-transform: uppercase; }
        dl { margin: 0; }
        .metadata { display: grid; grid-template-columns: 32mm 1fr; gap: 1mm 3mm; }
        dt { font-weight: bold; }
        dd { margin: 0; }
        .kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 2mm; }
        .kpi { border: 1px solid #000; padding: 2mm; }
        .kpi dd { margin-top: 1mm; font-size: 16pt; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; font-size: 9pt; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; page-break-inside: avoid; }
        th, td { border: 1px solid #000; padding: 1.5mm; text-align: left; vertical-align: top; }
        th { font-weight: bold; }
        .empty { text-align: center; }
        .print-button { margin: 0 0 5mm; border: 2px solid #000; padding: 2mm 4mm; color: #000; background: #fff; font-weight: bold; cursor: pointer; }
        @media print { .screen-only { display: none !important; } }
    </style>
</head>
<body>
@php($periodLabel = \Illuminate\Support\Carbon::parse($dateFrom)->format('d/m/Y').' - '.\Illuminate\Support\Carbon::parse($dateTo)->format('d/m/Y'))
<main>
    <button type="button" class="print-button screen-only" onclick="window.print()">Cetak</button>
    <header><h1>LAPORAN AKTIVITAS SALES</h1></header>
    <section aria-labelledby="metadata-title">
        <h2 id="metadata-title">Informasi Laporan</h2>
        <dl class="metadata"><dt>Sales</dt><dd>{{ $sales->name }}</dd><dt>Koordinator</dt><dd>{{ $coordinator?->name ?? '-' }}</dd><dt>Cabang</dt><dd>{{ $branch->name }}</dd><dt>Proyek</dt><dd>{{ $project->project_name }}</dd><dt>Periode</dt><dd>{{ $periodLabel }}</dd></dl>
    </section>
    <section aria-labelledby="summary-title">
        <h2 id="summary-title">RINGKASAN</h2>
        <dl class="kpis">@foreach(['total_agenda' => 'Total Agenda', 'agenda_done' => 'Agenda Selesai', 'total_lead' => 'Total Lead', 'face_to_face' => 'Tatap Muka', 'site_visit' => 'Cek Lokasi', 'utj' => 'UTJ'] as $key => $label)<div class="kpi"><dt>{{ $label }}</dt><dd>{{ $metrics[$key] }}</dd></div>@endforeach</dl>
    </section>
    <section aria-labelledby="agenda-title">
        <h2 id="agenda-title">DETAIL AGENDA</h2>
        <table><thead><tr><th>Tanggal</th><th>Kategori Aktivitas</th><th>Agenda</th><th>Lokasi</th><th>Hasil</th><th>Status</th></tr></thead><tbody>
            @forelse($agendas as $agenda)<tr><td>{{ $agenda->scheduled_date?->format('d/m/Y') ?? '-' }}</td><td>{{ $agenda->sales_activity_category ?: '-' }}</td><td>{{ $agenda->title }}</td><td>{{ $agenda->location ?: '-' }}</td><td>{{ $agenda->activity_result ?: '-' }}</td><td>{{ $agendaStatusLabels[$agenda->status] ?? $agenda->status }}</td></tr>@empty<tr><td colspan="6" class="empty">Belum ada agenda pada periode ini.</td></tr>@endforelse
        </tbody></table>
    </section>
    <section aria-labelledby="lead-title">
        <h2 id="lead-title">DETAIL LEAD</h2>
        <table><thead><tr><th>Tanggal Lead</th><th>Nama Konsumen</th><th>Sumber Lead</th><th>Kanal Masuk</th><th>Aktivitas Lead</th><th>Status</th><th>Proyek</th></tr></thead><tbody>
            @forelse($leads as $lead)<tr><td>{{ $lead->lead_date?->format('d/m/Y') ?? '-' }}</td><td>{{ $lead->customer_name }}</td><td>{{ $lead->effective_source ?: '-' }}</td><td>{{ $lead->platform ?: '-' }}</td><td>{{ $lead->campaign_name ?: '-' }}</td><td>{{ $lead->current_status?->label() ?? '-' }}</td><td>{{ $lead->project?->project_name ?? '-' }}</td></tr>@empty<tr><td colspan="7" class="empty">Belum ada lead pada periode ini.</td></tr>@endforelse
        </tbody></table>
    </section>
</main>
</body>
</html>
