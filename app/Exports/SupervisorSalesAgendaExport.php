<?php

namespace App\Exports;

use App\Exports\Concerns\ExcelStyle;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupervisorSalesAgendaExport
{
    use ExcelStyle;

    private const HEADERS = [
        'Tanggal Agenda', 'Koordinator', 'Sales', 'Cabang', 'Proyek', 'Kategori Aktivitas', 'Agenda', 'Lokasi', 'Hasil', 'Status', 'Ringkasan Aktivitas',
    ];

    public static function toBrowser(Collection $agendas, array $coordinatorNamesBySalesId, string $filename): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('RENCANA HARIAN SALES');
        self::writeHeaderRow($sheet, self::HEADERS);

        foreach ($agendas->values() as $index => $agenda) {
            $row = $index + 2;
            if (! is_array($agenda)) {
                $sheet->setCellValue(self::cell(1, $row), ExcelDate::dateTimeToExcel($agenda->scheduled_date));
            }

            if (is_array($agenda)) {
                $agendaDate = $agenda['date'] ?? null;
                if ($agendaDate !== null) {
                    $sheet->setCellValue(self::cell(1, $row), ExcelDate::dateTimeToExcel(new \DateTimeImmutable($agendaDate)));
                }
                $values = [
                    null,
                    $agenda['sales_name'] ?? null,
                    null,
                    $agenda['project_name'] ?? null,
                    null,
                    'Ringkasan agenda',
                    null,
                    null,
                    $agenda['submitted'] ? 'Terkirim' : 'Belum Terkirim',
                    ($agenda['total_minutes'] ?? 0).' / '.($agenda['target_minutes'] ?? 0).' menit; '.($agenda['activity_count'] ?? 0).' kegiatan; '.($agenda['late_activity_count'] ?? 0).' terlambat',
                ];
            } else {
                $values = [
                    self::coordinatorNames($coordinatorNamesBySalesId[$agenda->owner_user_id] ?? []),
                    $agenda->owner?->name,
                    $agenda->branch?->name,
                    $agenda->salesProject?->project_name ?? $agenda->project_name,
                    $agenda->sales_activity_category,
                    $agenda->title,
                    $agenda->location,
                    $agenda->activity_result,
                    $agenda->status,
                ];
            }

            foreach ($values as $column => $value) {
                $sheet->setCellValueExplicit(self::cell($column + 2, $row), $value === null ? '' : (string) $value, DataType::TYPE_STRING);
            }
        }

        $lastRow = $agendas->count() + 1;
        self::applyStyles($spreadsheet, self::HEADERS, $lastRow, [
            'A' => 16, 'B' => 24, 'C' => 22, 'D' => 18, 'E' => 24, 'F' => 22, 'G' => 30, 'H' => 24, 'I' => 32, 'J' => 16, 'K' => 40,
        ]);
        self::addAutoFilter($sheet, self::HEADERS, $lastRow);
        if ($lastRow > 1) {
            $sheet->getStyle("A2:A{$lastRow}")->getNumberFormat()->setFormatCode('DD/MM/YYYY');
        }

        return self::download($spreadsheet, $filename);
    }

    private static function coordinatorNames(array|string $names): string
    {
        return collect((array) $names)->filter()->unique()->sort()->implode('; ');
    }

    private static function download(Spreadsheet $spreadsheet, string $filename): BinaryFileResponse
    {
        $directory = storage_path('app/temp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $tempPath = tempnam($directory, 'supervisor-sales-agenda-');
        (new Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
