<?php

namespace App\Exports;

use App\Exports\Concerns\ExcelStyle;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesFeeReportExport
{
    use ExcelStyle;

    private const HEADERS = [
        'Sales', 'Koordinator', 'Proyek', 'Total Agenda', 'Agenda Selesai', 'Total Lead',
    ];

    public static function toBrowser(Collection $rows, array $report, string $filename): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('LAPORAN FEE SALES');
        self::writeHeaderRow($sheet, self::HEADERS);

        foreach ($rows->values() as $index => $row) {
            self::writeRow($sheet, $index + 2, $row);
        }

        $totalRow = $rows->count() + 2;
        self::writeTotalRow($sheet, $totalRow, $rows);
        self::applyStyles($spreadsheet, self::HEADERS, $totalRow, [
            'A' => 24, 'B' => 24, 'C' => 28, 'D' => 16, 'E' => 17, 'F' => 14,
        ]);
        self::addAutoFilter($sheet, self::HEADERS, $rows->count() + 1);
        $sheet->getStyle("A{$totalRow}:F{$totalRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FCC20F']],
        ]);

        $directory = storage_path('app/temp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $tempPath = tempnam($directory, 'sales-fee-report-');
        (new Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private static function writeRow($sheet, int $rowNumber, object $row): void
    {
        foreach ([
            $row->sales_name,
            $row->coordinator_name ?? '-',
            $row->project_name,
        ] as $column => $value) {
            $sheet->setCellValueExplicit(self::cell($column + 1, $rowNumber), (string) $value, DataType::TYPE_STRING);
        }

        foreach ([
            $row->agenda_total,
            $row->agenda_done,
            $row->lead_total,
        ] as $column => $value) {
            $sheet->setCellValue(self::cell($column + 4, $rowNumber), (int) $value);
        }
    }

    private static function writeTotalRow($sheet, int $rowNumber, Collection $rows): void
    {
        $sheet->setCellValueExplicit(self::cell(1, $rowNumber), 'TOTAL', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit(self::cell(2, $rowNumber), '', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit(self::cell(3, $rowNumber), '', DataType::TYPE_STRING);
        $sheet->setCellValue(self::cell(4, $rowNumber), $rows->sum(fn (object $row): int => (int) $row->agenda_total));
        $sheet->setCellValue(self::cell(5, $rowNumber), $rows->sum(fn (object $row): int => (int) $row->agenda_done));
        $sheet->setCellValue(self::cell(6, $rowNumber), $rows->sum(fn (object $row): int => (int) $row->lead_total));
    }
}
