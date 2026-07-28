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

class ExpenseReportExport
{
    use ExcelStyle;

    private const SUMMARY_HEADERS = ['Keterangan', 'Nilai'];

    private const DETAIL_HEADERS = [
        'Tanggal', 'Cabang', 'Proyek', 'Kategori', 'Deskripsi', 'Vendor / Penerima',
        'Metode Pembayaran', 'Nomor Referensi', 'Nominal', 'Status', 'Alasan Pembatalan',
        'Dibuat Oleh', 'Diperbarui Oleh', 'Dibuat Pada', 'Diperbarui Pada',
    ];

    private const RECAP_HEADERS = ['Cabang', 'Proyek', 'Kategori', 'Jumlah Transaksi', 'Total'];

    public static function toBrowser(Collection $expenses, Collection $recaps, array $summary, string $period, string $filename): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        self::writeSummary($spreadsheet, $summary, $period);
        self::writeDetail($spreadsheet, $expenses);
        self::writeRecap($spreadsheet, $recaps);
        $spreadsheet->setActiveSheetIndex(0);

        $directory = storage_path('app/temp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $tempPath = tempnam($directory, 'pengeluaran-');
        (new Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private static function writeSummary(Spreadsheet $spreadsheet, array $summary, string $period): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('RINGKASAN');
        self::writeHeaderRow($sheet, self::SUMMARY_HEADERS);
        $rows = [
            ['Periode', $period],
            ['Total Pengeluaran Aktif', $summary['total']],
            ['Jumlah Transaksi Aktif', $summary['count']],
            ['Rata-rata Transaksi', $summary['average']],
            ['Kategori Terbesar', $summary['top_category']['label'] ?? '-'],
            ['Cabang Terbesar', $summary['top_branch']['label'] ?? '-'],
            ['Proyek Terbesar', $summary['top_project']['label'] ?? '-'],
        ];
        foreach ($rows as $index => $row) {
            self::writeTypedRow($sheet, $index + 2, $row, [], in_array($index, [1, 2, 3], true) ? [2] : []);
        }
        self::styleSheet($spreadsheet, self::SUMMARY_HEADERS, count($rows) + 1, ['A' => 30, 'B' => 40]);
        $sheet->getStyle('B3:B3')->getNumberFormat()->setFormatCode('[$Rp-id-ID] #,##0.00');
        $sheet->getStyle('B5:B5')->getNumberFormat()->setFormatCode('[$Rp-id-ID] #,##0.00');
    }

    private static function writeDetail(Spreadsheet $spreadsheet, Collection $expenses): void
    {
        $sheet = $spreadsheet->createSheet();
        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));
        $sheet->setTitle('DETAIL PENGELUARAN');
        self::writeHeaderRow($sheet, self::DETAIL_HEADERS);
        foreach ($expenses->values() as $index => $expense) {
            self::writeTypedRow($sheet, $index + 2, [
                $expense->expense_date, $expense->branch?->name, $expense->project?->project_name,
                $expense->category?->name, $expense->description, $expense->vendor_name,
                $expense->payment_method ? ($expense::PAYMENT_METHODS[$expense->payment_method] ?? $expense->payment_method) : null,
                $expense->reference_number, (float) $expense->amount,
                $expense->status === $expense::STATUS_CANCELLED ? 'Dibatalkan' : 'Aktif',
                $expense->cancellation_reason, $expense->creator?->name, $expense->updatedBy?->name,
                $expense->created_at, $expense->updated_at,
            ], [1, 14, 15], [9]);
        }
        $lastRow = $expenses->count() + 1;
        self::styleSheet($spreadsheet, self::DETAIL_HEADERS, $lastRow, [
            'A' => 14, 'B' => 20, 'C' => 24, 'D' => 22, 'E' => 35, 'F' => 24, 'G' => 20,
            'H' => 20, 'I' => 18, 'J' => 14, 'K' => 32, 'L' => 22, 'M' => 22, 'N' => 20, 'O' => 20,
        ]);
        if ($lastRow > 1) {
            $sheet->getStyle("A2:A{$lastRow}")->getNumberFormat()->setFormatCode('DD/MM/YYYY');
            $sheet->getStyle("N2:O{$lastRow}")->getNumberFormat()->setFormatCode('DD/MM/YYYY HH:MM');
            $sheet->getStyle("I2:I{$lastRow}")->getNumberFormat()->setFormatCode('[$Rp-id-ID] #,##0.00');
        }
    }

    private static function writeRecap(Spreadsheet $spreadsheet, Collection $recaps): void
    {
        $sheet = $spreadsheet->createSheet();
        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));
        $sheet->setTitle('REKAP');
        self::writeHeaderRow($sheet, self::RECAP_HEADERS);
        foreach ($recaps->values() as $index => $recap) {
            self::writeTypedRow($sheet, $index + 2, [
                $recap->branch_name, $recap->project_name, $recap->category_name,
                (int) $recap->transaction_count, (float) $recap->total,
            ], [], [4, 5]);
        }
        $lastRow = $recaps->count() + 1;
        self::styleSheet($spreadsheet, self::RECAP_HEADERS, $lastRow, ['A' => 22, 'B' => 26, 'C' => 24, 'D' => 18, 'E' => 22]);
        if ($lastRow > 1) {
            $sheet->getStyle("E2:E{$lastRow}")->getNumberFormat()->setFormatCode('[$Rp-id-ID] #,##0.00');
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
            } elseif ($value !== null && (in_array($column, $numericColumns, true) || is_int($value) || is_float($value))) {
                $sheet->setCellValue($cell, $value);
            } else {
                $sheet->setCellValueExplicit($cell, $value === null ? '' : (string) $value, DataType::TYPE_STRING);
            }
        }
    }
}
