<?php

namespace App\Exports;

use App\Models\Branch;
use App\Models\Kavling;
use App\Models\LeadMaster;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

class DanaTalanganExport
{
    private static function headers(): array
    {
        return [
            'No', 'Tanggal', 'Nama Konsumen', 'Kav', 'Proyek',
            'Pinjam Nama', 'Pekerjaan', 'Status Kawin', 'Umur',
            'Marketing', 'Penyelesaian', 'Konfirmasi', 'Status',
        ];
    }

    private static function templateHeaders(): array
    {
        return array_merge(['Cabang'], self::headers());
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
            'A' => 5, 'B' => 14, 'C' => 30, 'D' => 10, 'E' => 25,
            'F' => 14, 'G' => 20, 'H' => 15, 'I' => 7,
            'J' => 20, 'K' => 30, 'L' => 14, 'M' => 10,
        ];

        if ($colCount === 14) {
            $defaultWidths = [
                'A' => 22, 'B' => 5, 'C' => 14, 'D' => 30, 'E' => 10, 'F' => 25,
                'G' => 14, 'H' => 20, 'I' => 15, 'J' => 7,
                'K' => 20, 'L' => 30, 'M' => 14, 'N' => 10,
            ];
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

    public static function toBrowser(Collection $records, string $filename): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Dana Talangan');

        $headers = self::headers();
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(self::cell($i + 1, 1), $h);
        }

        foreach ($records as $i => $r) {
            $row = $i + 2;
            $sheet->setCellValue(self::cell(1, $row), $i + 1);
            $sheet->setCellValue(self::cell(2, $row), $r->tanggal->format('d M Y'));
            $sheet->setCellValue(self::cell(3, $row), $r->nama_konsumen);
            $sheet->setCellValue(self::cell(4, $row), $r->kav ?? '—');
            $sheet->setCellValue(self::cell(5, $row), $r->project_name ?? '—');
            $sheet->setCellValue(self::cell(6, $row), $r->pinjam_nama ? 'YA' : 'TIDAK');
            $sheet->setCellValue(self::cell(7, $row), $r->pekerjaan ?? '—');
            $sheet->setCellValue(self::cell(8, $row), $r->status_perkawinan ?? '—');
            $sheet->setCellValue(self::cell(9, $row), $r->umur ?? '—');
            $sheet->setCellValue(self::cell(10, $row), $r->nama_marketing ?? '—');
            $sheet->setCellValue(self::cell(11, $row), $r->penyelesaian ?? '—');
            $sheet->setCellValue(self::cell(12, $row), $r->konfirmasi_keuangan ? '✓' : '—');
            $sheet->setCellValue(self::cell(13, $row), strtoupper($r->status));
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

        // --- Hidden helper sheet for cascading Kav dropdown ---
        $helperSheet = $spreadsheet->createSheet();
        $helperSheet->setTitle('KavLists');
        $helperSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VERYHIDDEN);

        $projects = LeadMaster::where('is_active', true)->orderBy('project_name')->get();
        $kavlingsByProject = Kavling::with('project')
            ->orderBy('kavling_code')
            ->get()
            ->groupBy('project_id');

        $projCol = 1;
        foreach ($projects as $p) {
            $colLetter = Coordinate::stringFromColumnIndex($projCol);
            $helperSheet->setCellValue($colLetter . '1', $p->project_name);

            $kavs = $kavlingsByProject->get($p->id, collect())
                ->pluck('kavling_code')
                ->unique()
                ->values()
                ->toArray();

            foreach ($kavs as $k => $code) {
                $helperSheet->setCellValue($colLetter . ($k + 2), $code);
            }

            $projCol++;
        }

        // --- Date format C:Tanggal ---
        $sheet->getStyle('C2:C' . $maxRow)
            ->getNumberFormat()
            ->setFormatCode('DD/MM/YYYY');
        $sheet->setCellValue('C2', date('Y-m-d'));

        // --- A:Cabang dropdown ---
        $branches = Branch::where('is_active', true)->pluck('name')->toArray();
        if (!empty($branches)) {
            $sheet->setDataValidation('A2:A' . $maxRow, self::listValidation($branches));
        }

        // --- F:Proyek dropdown (from helper sheet row 1) ---
        if ($projects->count() > 0) {
            $lastProjCol = Coordinate::stringFromColumnIndex($projects->count());
            $sheet->setDataValidation(
                'F2:F' . $maxRow,
                self::listValidation("'KavLists'!\$A\$1:\$" . $lastProjCol . "\$1")
            );
        }

        // --- E:Kav interdependent dropdown via OFFSET+MATCH ---
        if ($projects->count() > 0) {
            $kavValidation = new DataValidation();
            $kavValidation->setType(DataValidation::TYPE_LIST);
            $kavValidation->setErrorStyle(DataValidation::STYLE_STOP);
            $kavValidation->setAllowBlank(true);
            $kavValidation->setShowDropDown(false);
            $kavValidation->setFormula1('=OFFSET(KavLists!$A$1,1,MATCH(F2,KavLists!$1:$1,0)-1,500,1)');
            $sheet->setDataValidation('E2:E' . $maxRow, $kavValidation);
        }

        // --- G:Pinjam Nama dropdown ---
        $sheet->setDataValidation('G2:G' . $maxRow, self::listValidation(['YA', 'TIDAK']));

        // --- M:Konfirmasi dropdown ---
        $sheet->setDataValidation('M2:M' . $maxRow, self::listValidation(['YA', 'TIDAK']));

        // --- N:Status dropdown ---
        $sheet->setDataValidation('N2:N' . $maxRow, self::listValidation(['aktif', 'lunas']));

        self::applyStyles($spreadsheet, $headers, $maxRow);

        $writer = new Xlsx($spreadsheet);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="template-dana-talangan.xlsx"');
        header('Cache-Control: max-age=0');
        $writer->save('php://output');
        exit;
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
}
