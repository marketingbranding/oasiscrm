<?php

namespace App\Exports;

use App\Exports\Concerns\ExcelStyle;
use App\Models\Branch;
use App\Models\Kavling;
use App\Models\LeadMaster;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DanaTalanganExport
{
    use ExcelStyle;

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

    private static function exportWidths(): array
    {
        return [
            'A' => 5, 'B' => 14, 'C' => 30, 'D' => 10, 'E' => 25,
            'F' => 14, 'G' => 20, 'H' => 15, 'I' => 7,
            'J' => 20, 'K' => 30, 'L' => 14, 'M' => 10,
        ];
    }

    private static function templateWidths(): array
    {
        return [
            'A' => 22, 'B' => 5, 'C' => 14, 'D' => 30, 'E' => 10, 'F' => 25,
            'G' => 14, 'H' => 20, 'I' => 15, 'J' => 7,
            'K' => 20, 'L' => 30, 'M' => 14, 'N' => 10,
        ];
    }

    public static function toBrowser(Collection $records, string $filename): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Dana Talangan');

        $headers = self::headers();
        self::writeHeaderRow($sheet, $headers);

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
        self::applyStyles($spreadsheet, $headers, $rowCount, self::exportWidths());
        self::addAutoFilter($sheet, $headers, $rowCount);

        $writer = new Xls($spreadsheet);
        return self::downloadXlsx($writer, $filename);
    }

    public static function generateTemplate(): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = self::templateHeaders();
        self::generateTemplateOpen($spreadsheet, $headers);

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

        // --- C:Tanggal date format ---
        self::dateColumnStyle($sheet, 'C2:C' . $maxRow, date('Y-m-d'));

        // --- A:Cabang dropdown ---
        $branches = Branch::where('is_active', true)->pluck('name')->toArray();
        self::branchDropdown($sheet, 'A', $maxRow, $branches);

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

        self::applyStyles($spreadsheet, $headers, $maxRow, self::templateWidths());

        $writer = new Xlsx($spreadsheet);
        return self::downloadXlsx($writer, 'template-dana-talangan.xlsx');
    }
}
