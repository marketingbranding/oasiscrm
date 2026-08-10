<?php

namespace App\Exports;

use App\Exports\Concerns\ExcelStyle;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesAgendaExport
{
    use ExcelStyle;

    private const HEADERS = [
        'Tanggal Agenda', 'Kategori Aktivitas', 'Sales', 'Cabang', 'Proyek', 'Agenda', 'Lokasi', 'Hasil', 'Status',
    ];

    public static function toBrowser(Collection $agendas, string $filename): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('AGENDA SALES');
        self::writeHeaderRow($sheet, self::HEADERS);

        foreach ($agendas->values() as $index => $agenda) {
            $row = $index + 2;
            $sheet->setCellValue(self::cell(1, $row), ExcelDate::dateTimeToExcel($agenda->scheduled_date));
            foreach ([
                $agenda->sales_activity_category,
                $agenda->owner?->name,
                $agenda->branch?->name,
                $agenda->salesProject?->project_name ?? $agenda->project_name,
                $agenda->title,
                $agenda->location,
                $agenda->activity_result,
                $agenda->status,
            ] as $column => $value) {
                $sheet->setCellValueExplicit(self::cell($column + 2, $row), $value === null ? '' : (string) $value, DataType::TYPE_STRING);
            }
        }

        $lastRow = $agendas->count() + 1;
        self::applyStyles($spreadsheet, self::HEADERS, $lastRow, [
            'A' => 16, 'B' => 22, 'C' => 22, 'D' => 18, 'E' => 24, 'F' => 30, 'G' => 24, 'H' => 32, 'I' => 16,
        ]);
        self::addAutoFilter($sheet, self::HEADERS, $lastRow);
        if ($lastRow > 1) {
            $sheet->getStyle("A2:A{$lastRow}")->getNumberFormat()->setFormatCode('DD/MM/YYYY');
        }

        return self::download($spreadsheet, $filename, 'sales-agenda-');
    }

    private static function download(Spreadsheet $spreadsheet, string $filename, string $prefix): BinaryFileResponse
    {
        $directory = storage_path('app/temp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $tempPath = tempnam($directory, $prefix);
        (new Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
