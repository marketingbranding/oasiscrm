<?php

namespace App\Exports;

use App\Models\Branch;
use App\Models\LeadMaster;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

class ContentItemExport
{
    private static function headers(): array
    {
        return [
            'Judul', 'Platform', 'Cabang', 'Proyek',
            'Tanggal', 'Status', 'Catatan', 'Dibuat Oleh',
        ];
    }

    private static function templateHeaders(): array
    {
        return ['Cabang', 'Judul', 'Platform', 'Proyek', 'Tanggal', 'Status', 'Catatan'];
    }

    private static function applyStyles(Spreadsheet $spreadsheet, array $headers, int $lastRow): void
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

        $defaultWidths = [
            'A' => 14, 'B' => 30, 'C' => 14, 'D' => 22,
            'E' => 14, 'F' => 12, 'G' => 30, 'H' => 18,
        ];

        if ($colCount === 7) {
            $defaultWidths = ['A' => 14, 'B' => 30, 'C' => 14, 'D' => 22, 'E' => 14, 'F' => 12, 'G' => 30];
        }

        foreach ($defaultWidths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        $sheet->freezePane('A2');
    }

    private static function cell(int $col, int $row): string
    {
        return Coordinate::stringFromColumnIndex($col) . $row;
    }

    private static function listValidation(array|string $source): DataValidation
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

    public static function toBrowser(Collection $records, string $filename): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Content Calendar');

        $headers = self::headers();
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(self::cell($i + 1, 1), $h);
        }

        foreach ($records as $i => $r) {
            $row = $i + 2;
            $sheet->setCellValue(self::cell(1, $row), $r->title);
            $sheet->setCellValue(self::cell(2, $row), $r->platform ?? '—');
            $sheet->setCellValue(self::cell(3, $row), $r->branch->name ?? '—');
            $sheet->setCellValue(self::cell(4, $row), $r->project_name ?? '—');
            $sheet->setCellValue(self::cell(5, $row), $r->scheduled_date->format('d M Y'));
            $sheet->setCellValue(self::cell(6, $row), strtoupper($r->status));
            $sheet->setCellValue(self::cell(7, $row), $r->notes ?? '—');
            $sheet->setCellValue(self::cell(8, $row), $r->creator->name ?? '—');
        }

        $rowCount = $records->count() + 1;
        self::applyStyles($spreadsheet, $headers, $rowCount);

        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->setAutoFilter('A1:' . $lastCol . $rowCount);

        $writer = new Xlsx($spreadsheet);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer->save('php://output');
        exit;
    }

    public static function generateTemplate(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Template');

        $headers = self::templateHeaders();
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(self::cell($i + 1, 1), $h);
        }

        $maxRow = 101;

        // --- A:Cabang dropdown ---
        $branches = Branch::where('is_active', true)->pluck('name')->toArray();
        if (!empty($branches)) {
            $sheet->setDataValidation('A2:A' . $maxRow, self::listValidation($branches));
        }

        // --- C:Platform dropdown ---
        $platforms = ['Instagram', 'Facebook', 'TikTok', 'Twitter / X', 'Website', 'Blog', 'YouTube', 'LinkedIn', 'WhatsApp', 'Email'];
        $sheet->setDataValidation('C2:C' . $maxRow, self::listValidation($platforms));

        // --- D:Proyek dropdown ---
        $projects = LeadMaster::where('is_active', true)->orderBy('project_name')->pluck('project_name')->toArray();
        if (!empty($projects)) {
            $sheet->setDataValidation('D2:D' . $maxRow, self::listValidation($projects));
        }

        // --- E:Tanggal date format ---
        $sheet->getStyle('E2:E' . $maxRow)
            ->getNumberFormat()
            ->setFormatCode('DD/MM/YYYY');
        $sheet->setCellValue('E2', date('Y-m-d'));

        // --- F:Status dropdown ---
        $sheet->setDataValidation('F2:F' . $maxRow, self::listValidation(['rencana', 'terbit']));

        self::applyStyles($spreadsheet, $headers, $maxRow);

        $writer = new Xlsx($spreadsheet);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="template-content-calendar.xlsx"');
        header('Cache-Control: max-age=0');
        $writer->save('php://output');
        exit;
    }
}
