<?php

namespace App\Exports;

use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use Illuminate\Support\Collection;

class ContentItemExport
{
    private static function headers(): array
    {
        return [
            'Judul', 'Platform', 'Cabang', 'Proyek',
            'Tanggal', 'Status', 'Catatan', 'Dibuat Oleh',
        ];
    }

    public static function toBrowser(Collection $records, string $filename): void
    {
        $writer = new Writer();

        $writer->openToBrowser($filename);

        $writer->addRow(Row::fromValues(self::headers()));

        foreach ($records as $r) {
            $writer->addRow(Row::fromValues([
                $r->title,
                $r->platform ?? '—',
                $r->branch->name ?? '—',
                $r->project_name ?? '—',
                $r->scheduled_date->format('d M Y'),
                strtoupper($r->status),
                $r->notes ?? '—',
                $r->creator->name ?? '—',
            ]));
        }

        $writer->close();
        exit;
    }
}
