<?php

namespace App\Exports;

use App\Exports\Concerns\ExcelStyle;
use App\Models\Branch;
use App\Models\LeadMaster;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ContentItemExport
{
    use ExcelStyle;

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

    private static function exportWidths(): array
    {
        return ['A' => 30, 'B' => 14, 'C' => 14, 'D' => 22, 'E' => 14, 'F' => 12, 'G' => 30, 'H' => 18];
    }

    private static function templateWidths(): array
    {
        return ['A' => 14, 'B' => 30, 'C' => 14, 'D' => 22, 'E' => 14, 'F' => 12, 'G' => 30];
    }

    public static function toBrowser(Collection $records, string $filename): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Content Calendar');

        $headers = self::headers();
        self::writeHeaderRow($sheet, $headers);

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
        self::applyStyles($spreadsheet, $headers, $rowCount, self::exportWidths());
        self::addAutoFilter($sheet, $headers, $rowCount);

        $writer = new Xlsx($spreadsheet);
        self::downloadXlsx($writer, $filename);
    }

    public static function generateTemplate(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = self::templateHeaders();
        self::generateTemplateOpen($spreadsheet, $headers);

        $maxRow = 101;

        // --- A:Cabang dropdown ---
        $branches = Branch::where('is_active', true)->pluck('name')->toArray();
        self::branchDropdown($sheet, 'A', $maxRow, $branches);

        // --- C:Platform dropdown ---
        $platforms = ['Instagram', 'Facebook', 'TikTok', 'Twitter / X', 'Website', 'Blog', 'YouTube', 'LinkedIn', 'WhatsApp', 'Email'];
        $sheet->setDataValidation('C2:C' . $maxRow, self::listValidation($platforms));

        // --- D:Proyek dropdown ---
        $projects = LeadMaster::where('is_active', true)->orderBy('project_name')->pluck('project_name')->toArray();
        if (!empty($projects)) {
            $sheet->setDataValidation('D2:D' . $maxRow, self::listValidation($projects));
        }

        // --- E:Tanggal date ---
        self::dateColumnStyle($sheet, 'E2:E' . $maxRow, date('Y-m-d'));

        // --- F:Status dropdown ---
        $sheet->setDataValidation('F2:F' . $maxRow, self::listValidation(['rencana', 'terbit']));

        self::applyStyles($spreadsheet, $headers, $maxRow, self::templateWidths());

        $writer = new Xlsx($spreadsheet);
        self::downloadXlsx($writer, 'template-content-calendar.xlsx');
    }
}
