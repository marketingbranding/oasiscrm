<?php

namespace App\Exports;

use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use Illuminate\Support\Collection;

class LeadEventExport
{
    private static function headers(): array
    {
        return [
            'Event ID', 'Cabang', 'Proyek', 'Sumber Lead',
            'Tgl Mulai', 'Tgl Selesai', 'Anggaran', 'Cost/Lead', 'Status', 'Catatan',
        ];
    }

    public static function toBrowser(Collection $records, string $filename): void
    {
        $writer = new Writer();

        $writer->openToBrowser($filename);

        $writer->addRow(Row::fromValues(self::headers()));

        foreach ($records as $r) {
            $writer->addRow(Row::fromValues([
                $r->event_id ?? '—',
                $r->branch->name ?? '—',
                $r->project_name,
                $r->lead_source,
                $r->start_date->format('d M Y'),
                $r->end_date?->format('d M Y') ?? '—',
                $r->total_budget ? 'Rp' . number_format($r->total_budget, 0, ',', '.') : '—',
                $r->costPerLead() !== null ? 'Rp' . number_format($r->costPerLead(), 0, ',', '.') : '—',
                strtoupper($r->status),
                $r->notes ?? '—',
            ]));
        }

        $writer->close();
        exit;
    }
}
