<?php

namespace App\Exports;

use App\Exports\Concerns\ExcelStyle;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesPocketbookExport
{
    use ExcelStyle;

    private const WEEKLY_HEADERS = [
        'Periode Mulai', 'Periode Selesai', 'Sales', 'Cabang', 'Proyek', 'Lead Baru',
        'Dihubungi', 'Tatap Muka', 'Survey Lokasi', 'UTJ', 'Berkas Awal Lengkap', 'Akad',
        'Agenda Selesai', 'Konversi Lead ke Dihubungi', 'Konversi Dihubungi ke Tatap Muka',
        'Konversi Tatap Muka ke Survey', 'Konversi Survey ke UTJ', 'Konversi UTJ ke Berkas',
        'Konversi Berkas ke Akad', 'Input Terakhir',
    ];

    private const LEAD_HEADERS = [
        'Tanggal Lead', 'Sales', 'Cabang', 'Proyek', 'Nama Konsumen', 'Nomor HP', 'Sumber Lead',
        'Dihubungi', 'Tatap Muka', 'Survey', 'UTJ', 'Berkas Awal', 'Akad', 'Catatan',
        'Dibuat', 'Diperbarui', 'External Sync ID', 'Sumber (Sheet)', 'Platform', 'Campaign',
        'Siklus Saat Ini', 'Freelance',
    ];

    private const AGENDA_HEADERS = [
        'Tanggal', 'Sales', 'Cabang', 'Proyek', 'Jam Mulai', 'Jam Selesai', 'Durasi',
        'Kategori', 'Agenda', 'Lokasi', 'Hasil', 'Status',
    ];

    public static function toBrowser(Collection $weeklyRows, Collection $leads, Collection $agendas, array $period, string $filename): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        self::writeWeeklySheet($spreadsheet, $weeklyRows, $period);
        self::writeLeadSheet($spreadsheet, $leads);
        self::writeAgendaSheet($spreadsheet, $agendas);
        $spreadsheet->setActiveSheetIndex(0);

        $directory = storage_path('app/temp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $tempPath = tempnam($directory, 'sales-pocketbook-');
        (new Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private static function writeWeeklySheet(Spreadsheet $spreadsheet, Collection $rows, array $period): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('REKAP MINGGUAN');
        self::writeHeaderRow($sheet, self::WEEKLY_HEADERS);

        foreach ($rows->values() as $index => $metrics) {
            $row = $index + 2;
            $values = [
                $period['start'],
                $period['end'],
                $metrics['sales']->name,
                $metrics['branch']?->name,
                $metrics['project']->project_name,
                $metrics['lead_new'],
                $metrics['contacted'],
                $metrics['met'],
                $metrics['surveyed'],
                $metrics['utj'],
                $metrics['documents_completed'],
                $metrics['akad'],
                $metrics['agenda_completed'],
                self::percentage($metrics['conversions']['lead_contacted']),
                self::percentage($metrics['conversions']['contacted_met']),
                self::percentage($metrics['conversions']['met_survey']),
                self::percentage($metrics['conversions']['survey_utj']),
                self::percentage($metrics['conversions']['utj_documents']),
                self::percentage($metrics['conversions']['documents_akad']),
                $metrics['last_input'],
            ];
            self::writeTypedRow($sheet, $row, $values, [1, 2, 20]);
        }

        $lastRow = $rows->count() + 1;
        self::styleSheet($spreadsheet, self::WEEKLY_HEADERS, $lastRow, [
            'A' => 14, 'B' => 14, 'C' => 22, 'D' => 18, 'E' => 24, 'F' => 12,
            'G' => 12, 'H' => 14, 'I' => 14, 'J' => 12, 'K' => 22, 'L' => 12,
            'M' => 16, 'N' => 24, 'O' => 29, 'P' => 27, 'Q' => 23, 'R' => 24,
            'S' => 24, 'T' => 20,
        ]);
        if ($lastRow > 1) {
            $sheet->getStyle("A2:B{$lastRow}")->getNumberFormat()->setFormatCode('DD/MM/YYYY');
            $sheet->getStyle("T2:T{$lastRow}")->getNumberFormat()->setFormatCode('DD/MM/YYYY HH:MM');
            foreach (['N', 'O', 'P', 'Q', 'R', 'S'] as $column) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('0.0%');
            }
        }
    }

    private static function writeLeadSheet(Spreadsheet $spreadsheet, Collection $leads): void
    {
        $sheet = $spreadsheet->createSheet();
        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));
        $sheet->setTitle('LEAD HARIAN');
        self::writeHeaderRow($sheet, self::LEAD_HEADERS);

        foreach ($leads->values() as $index => $lead) {
            self::writeTypedRow($sheet, $index + 2, [
                $lead->lead_date,
                $lead->sales?->name,
                $lead->branch?->name,
                $lead->project?->project_name,
                $lead->customer_name,
                $lead->phone,
                $lead->source_name_snapshot ?: $lead->effective_source,
                $lead->contacted_at,
                $lead->met_at,
                $lead->surveyed_at,
                $lead->utj_at,
                $lead->documents_completed_at,
                $lead->akad_at,
                $lead->notes,
                $lead->created_at,
                $lead->updated_at,
                $lead->external_sync_id,
                $lead->source,
                $lead->platform,
                $lead->campaign_name ?: $lead->campaign_id,
                $lead->current_status?->label(),
                $lead->is_freelance ? 'YA' : 'TIDAK',
            ], [1, 8, 9, 10, 11, 12, 13, 15, 16]);
        }

        $lastRow = $leads->count() + 1;
        self::styleSheet($spreadsheet, self::LEAD_HEADERS, $lastRow, [
            'A' => 14, 'B' => 22, 'C' => 18, 'D' => 24, 'E' => 26, 'F' => 20,
            'G' => 20, 'H' => 20, 'I' => 20, 'J' => 20, 'K' => 20, 'L' => 25,
            'M' => 20, 'N' => 30, 'O' => 20, 'P' => 20, 'Q' => 38, 'R' => 20,
            'S' => 18, 'T' => 24, 'U' => 22, 'V' => 14,
        ]);
        if ($lastRow > 1) {
            $sheet->getStyle("A2:A{$lastRow}")->getNumberFormat()->setFormatCode('DD/MM/YYYY');
            foreach (['H', 'I', 'J', 'K', 'L', 'M', 'O', 'P'] as $column) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('DD/MM/YYYY HH:MM');
            }
        }
    }

    private static function writeAgendaSheet(Spreadsheet $spreadsheet, Collection $agendas): void
    {
        $sheet = $spreadsheet->createSheet();
        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));
        $sheet->setTitle('AGENDA HARIAN');
        self::writeHeaderRow($sheet, self::AGENDA_HEADERS);

        foreach ($agendas->values() as $index => $agenda) {
            self::writeTypedRow($sheet, $index + 2, [
                $agenda->scheduled_date,
                $agenda->owner?->name,
                $agenda->branch?->name,
                $agenda->salesProject?->project_name ?? $agenda->project_name,
                self::excelTime($agenda->start_time),
                self::excelTime($agenda->end_time),
                $agenda->duration_minutes,
                $agenda->sales_activity_category,
                $agenda->title,
                $agenda->location,
                $agenda->activity_result,
                $agenda->status,
            ], [1], [5, 6]);
        }

        $lastRow = $agendas->count() + 1;
        self::styleSheet($spreadsheet, self::AGENDA_HEADERS, $lastRow, [
            'A' => 16, 'B' => 22, 'C' => 18, 'D' => 24, 'E' => 12, 'F' => 12,
            'G' => 12, 'H' => 22, 'I' => 28, 'J' => 22, 'K' => 32, 'L' => 16,
        ]);
        if ($lastRow > 1) {
            $sheet->getStyle("A2:A{$lastRow}")->getNumberFormat()->setFormatCode('DD/MM/YYYY');
            $sheet->getStyle("E2:F{$lastRow}")->getNumberFormat()->setFormatCode('HH:MM');
        }
    }

    private static function styleSheet(Spreadsheet $spreadsheet, array $headers, int $lastRow, array $widths): void
    {
        self::applyStyles($spreadsheet, $headers, $lastRow, $widths);
        self::addAutoFilter($spreadsheet->getActiveSheet(), $headers, $lastRow);
    }

    private static function writeTypedRow(Worksheet $sheet, int $row, array $values, array $dateColumns = [], array $numericColumns = []): void
    {
        foreach ($values as $index => $value) {
            $column = $index + 1;
            $cell = self::cell($column, $row);
            if ($value !== null && in_array($column, $dateColumns, true)) {
                $sheet->setCellValue($cell, ExcelDate::dateTimeToExcel($value));
            } elseif ($value !== null && (is_int($value) || is_float($value) || in_array($column, $numericColumns, true))) {
                $sheet->setCellValue($cell, $value);
            } else {
                // Explicit text prevents values beginning with =, +, - or @ from becoming formulas.
                $sheet->setCellValueExplicit($cell, $value === null ? '' : (string) $value, DataType::TYPE_STRING);
            }
        }
    }

    private static function percentage(?float $value): ?float
    {
        return $value === null ? null : $value / 100;
    }

    private static function excelTime(?string $value): ?float
    {
        if (! $value) {
            return null;
        }

        [$hours, $minutes, $seconds] = array_pad(array_map('intval', explode(':', $value)), 3, 0);

        return ($hours * 3600 + $minutes * 60 + $seconds) / 86400;
    }
}
