<?php

namespace App\Exports;

use App\Exports\Concerns\ExcelStyle;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CoordinatorSalesLeadExport
{
    use ExcelStyle;

    private const HEADERS = [
        'Tanggal Lead', 'Nama Konsumen', 'No HP', 'Sumber Lead', 'Kanal Masuk', 'Aktivitas Lead',
        'ID Promo', 'Sales PIC', 'Cabang', 'Proyek', 'Status Lead', 'Catatan', 'Status Sync',
    ];

    public static function toBrowser(Collection $leads, string $filename): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('LEAD SALES');
        self::writeHeaderRow($sheet, self::HEADERS);

        foreach ($leads->values() as $index => $lead) {
            $row = $index + 2;
            $sheet->setCellValue(self::cell(1, $row), ExcelDate::dateTimeToExcel($lead->lead_date));
            foreach ([
                $lead->customer_name,
                $lead->phone,
                $lead->effective_source,
                $lead->platform,
                $lead->campaign_name ?: $lead->campaign_id,
                $lead->id_promo,
                $lead->sales?->name,
                $lead->branch?->name,
                $lead->project?->project_name,
                $lead->current_status?->label(),
                $lead->notes,
                match ($lead->sync_status) {
                    'synced' => 'Tersinkron',
                    'pending_update' => 'Perlu Sync Ulang',
                    'sync_failed' => 'Sync Gagal',
                    default => 'Belum Sync',
                },
            ] as $column => $value) {
                $sheet->setCellValueExplicit(self::cell($column + 2, $row), $value === null ? '' : (string) $value, DataType::TYPE_STRING);
            }
        }

        $lastRow = $leads->count() + 1;
        self::applyStyles($spreadsheet, self::HEADERS, $lastRow, [
            'A' => 16, 'B' => 28, 'C' => 20, 'D' => 22, 'E' => 20, 'F' => 24, 'G' => 18,
            'H' => 22, 'I' => 18, 'J' => 24, 'K' => 18, 'L' => 32, 'M' => 28,
        ]);
        self::addAutoFilter($sheet, self::HEADERS, $lastRow);
        if ($lastRow > 1) {
            $sheet->getStyle("A2:A{$lastRow}")->getNumberFormat()->setFormatCode('DD/MM/YYYY');
        }

        $directory = storage_path('app/temp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $tempPath = tempnam($directory, 'coordinator-sales-lead-');
        (new Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
