<?php

namespace App\Exports;

use App\Data\BranchParityReport;
use App\Exports\Concerns\ExcelStyle;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class BranchParityReportExport
{
    use ExcelStyle;

    public static function toBrowser(BranchParityReport $report): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Ringkasan');
        $rows = [
            ['Cabang', $report->branch->name],
            ['Kode Cabang', $report->branch->code],
            ['Status', $report->status],
            ...collect($report->metrics)->map(fn (mixed $value, string $key): array => [$key, is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value])->values()->all(),
        ];
        foreach ($rows as $index => $row) {
            $summary->setCellValue('A'.($index + 1), $row[0]);
            $summary->setCellValue('B'.($index + 1), $row[1] ?? '');
        }

        $differences = $spreadsheet->createSheet();
        $differences->setTitle('Selisih');
        self::writeHeaderRow($differences, ['Proyek', 'Status', 'Kunci Legacy', 'ID Aplikasi OASIS', 'Kavling', 'Kolom Berbeda']);
        foreach ($report->differences as $index => $difference) {
            $row = $index + 2;
            foreach (array_values([
                $difference['project_id'] ?? null,
                $difference['status'] ?? null,
                $difference['legacy_key'] ?? null,
                $difference['oasis_application_id'] ?? null,
                $difference['kavling'] ?? null,
                implode(', ', $difference['mismatch_fields'] ?? []),
            ]) as $column => $value) {
                $differences->setCellValue(self::cell($column + 1, $row), $value ?? '—');
            }
        }

        $writer = new Xlsx($spreadsheet);

        return self::downloadXlsx($writer, 'laporan-paritas-'.$report->branch->code.'.xlsx');
    }
}
