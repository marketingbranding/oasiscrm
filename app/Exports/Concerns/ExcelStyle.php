<?php

namespace App\Exports\Concerns;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

trait ExcelStyle
{
    protected static function cell(int $col, int $row): string
    {
        return Coordinate::stringFromColumnIndex($col) . $row;
    }

    protected static function listValidation(array|string $source): DataValidation
    {
        $validation = new DataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowDropDown(false);

        if (is_array($source)) {
            $validation->setFormula1('"' . implode(',', $source) . '"');
        } else {
            $validation->setFormula1('=' . $source);
        }

        return $validation;
    }

    protected static function applyStyles(Spreadsheet $spreadsheet, array $headers, int $lastRow, array $defaultWidths): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $colCount = count($headers);
        $lastCol = Coordinate::stringFromColumnIndex($colCount);

        $sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11, 'name' => 'Times New Roman'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '000000']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        if ($lastRow > 1) {
            $sheet->getStyle('A2:' . $lastCol . $lastRow)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'font' => ['size' => 10, 'name' => 'Times New Roman'],
            ]);
        }

        foreach ($defaultWidths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        $sheet->freezePane('A2');
    }

    protected static function downloadXlsx(Xlsx $writer, string $filename): BinaryFileResponse
    {
        $tempPath = storage_path('app/temp/' . $filename);
        $dir = dirname($tempPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $writer->save($tempPath);

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    protected static function generateTemplateOpen(Spreadsheet $spreadsheet, array $headers, int $maxRow = 101): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Template');

        foreach ($headers as $i => $h) {
            $sheet->setCellValue(self::cell($i + 1, 1), $h);
        }
    }

    protected static function branchDropdown($sheet, string $col = 'A', int $maxRow = 101, array $branches = []): void
    {
        if (!empty($branches)) {
            $sheet->setDataValidation($col . '2:' . $col . $maxRow, self::listValidation($branches));
        }
    }

    protected static function dateColumnStyle($sheet, string $range, string $dateValue = null): void
    {
        $sheet->getStyle($range)
            ->getNumberFormat()
            ->setFormatCode('DD/MM/YYYY');
        if ($dateValue) {
            $parts = explode(':', $range);
            $sheet->setCellValue($parts[0], $dateValue);
        }
    }

    protected static function addAutoFilter($sheet, array $headers, int $rowCount): void
    {
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->setAutoFilter('A1:' . $lastCol . $rowCount);
    }

    protected static function writeHeaderRow($sheet, array $headers): void
    {
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(self::cell($i + 1, 1), $h);
        }
    }
}
