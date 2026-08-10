<?php

namespace App\Exports;

use App\Exports\Concerns\ExcelStyle;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupervisorSalesLeadExport
{
    use ExcelStyle;

    private const HEADERS = [
        'Tanggal Lead', 'Koordinator', 'Sales PIC', 'Nama Konsumen', 'No HP', 'Cabang', 'Proyek',
        'Sumber Lead', 'Kanal Masuk', 'Aktivitas Lead', 'Status Lead', 'Status Sync',
    ];

    public static function toBrowser(Collection $leads, array $coordinatorNamesBySalesId, string $filename): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('LEAD SALES');
        self::writeHeaderRow($sheet, self::HEADERS);

        foreach ($leads->values() as $index => $lead) {
            $row = $index + 2;
            $sheet->setCellValue(self::cell(1, $row), ExcelDate::dateTimeToExcel($lead->lead_date));

            foreach ([
                self::coordinatorNames($coordinatorNamesBySalesId[$lead->sales_user_id] ?? []),
                $lead->sales?->name,
                $lead->customer_name,
                $lead->phone,
                $lead->branch?->name,
                $lead->project?->project_name,
                $lead->effective_source,
                $lead->platform,
                $lead->campaign_name ?: $lead->campaign_id,
                $lead->current_status?->label(),
                self::syncLabel($lead->sync_status),
            ] as $column => $value) {
                $sheet->setCellValueExplicit(self::cell($column + 2, $row), $value === null ? '' : (string) $value, DataType::TYPE_STRING);
            }
        }

        $lastRow = $leads->count() + 1;
        self::applyStyles($spreadsheet, self::HEADERS, $lastRow, [
            'A' => 16, 'B' => 24, 'C' => 22, 'D' => 28, 'E' => 20, 'F' => 18,
            'G' => 24, 'H' => 22, 'I' => 20, 'J' => 24, 'K' => 18, 'L' => 20,
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

    private static function syncLabel(?string $status): string
    {
        return match ($status) {
            'synced' => 'Tersinkron',
            'pending_update' => 'Perlu Sync Ulang',
            'sync_failed' => 'Sync Gagal',
            default => 'Belum Sync',
        };
    }

    private static function download(Spreadsheet $spreadsheet, string $filename): BinaryFileResponse
    {
        $directory = storage_path('app/temp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $tempPath = tempnam($directory, 'supervisor-sales-lead-');
        (new Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
