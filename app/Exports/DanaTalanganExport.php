<?php

namespace App\Exports;

use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use Illuminate\Support\Collection;

class DanaTalanganExport
{
    private static function headers(): array
    {
        return [
            'No', 'Tanggal', 'Nama Konsumen', 'Kav', 'Proyek',
            'Pinjam Nama', 'Pekerjaan', 'Status Kawin', 'Umur',
            'Marketing', 'Penyelesaian', 'Konfirmasi', 'Status',
        ];
    }

    public static function toBrowser(Collection $records, string $filename): void
    {
        $writer = new Writer();

        $writer->openToBrowser($filename);

        $writer->addRow(Row::fromValues(self::headers()));

        foreach ($records as $i => $r) {
            $writer->addRow(Row::fromValues([
                $i + 1,
                $r->tanggal->format('d M Y'),
                $r->nama_konsumen,
                $r->kav ?? '—',
                $r->project_name ?? '—',
                $r->pinjam_nama ? 'YA' : 'TIDAK',
                $r->pekerjaan ?? '—',
                $r->status_perkawinan ?? '—',
                $r->umur ?? '—',
                $r->nama_marketing ?? '—',
                $r->penyelesaian ?? '—',
                $r->konfirmasi_keuangan ? '✓' : '—',
                strtoupper($r->status),
            ]));
        }

        $writer->close();
        exit;
    }
}
