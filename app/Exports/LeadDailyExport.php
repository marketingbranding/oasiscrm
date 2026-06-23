<?php

namespace App\Exports;

use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use Illuminate\Support\Collection;

class LeadDailyExport
{
    private static function headers(): array
    {
        return [
            'Tanggal', 'Event ID', 'Proyek', 'Cabang',
            'Hari Ke', 'Leads', 'Kumulatif', 'Achieve %',
        ];
    }

    public static function toBrowser(Collection $records, string $filename): void
    {
        $writer = new Writer();

        $writer->openToBrowser($filename);

        $writer->addRow(Row::fromValues(self::headers()));

        foreach ($records as $r) {
            $writer->addRow(Row::fromValues([
                $r->date->format('d M Y'),
                $r->leadEvent->event_id ?? '#' . $r->lead_event_id,
                $r->leadEvent->project_name,
                $r->branch->name ?? '—',
                $r->day_number ?? '—',
                $r->leads_count,
                $r->cumulative_leads,
                $r->achievement_pct !== null ? number_format($r->achievement_pct, 0) . '%' : '—',
            ]));
        }

        $writer->close();
        exit;
    }
}
