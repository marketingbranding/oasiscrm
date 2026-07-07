<?php

namespace App\Exports;

use App\Exports\Concerns\ExcelStyle;
use App\Models\Branch;
use App\Models\LeadMaster;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContentItemExport
{
    use ExcelStyle;

    private static function headers(): array
    {
        return [
            'Task', 'Detail', 'Channel', 'Cabang', 'Proyek',
            'Start', 'Deadline', 'Durasi', 'Priority', 'PIC', 'Status', 'Catatan', 'Dibuat Oleh',
        ];
    }

    private static function templateHeaders(): array
    {
        return ['Cabang', 'Task', 'Detail', 'Channel', 'Proyek', 'Start', 'Deadline', 'Priority', 'PIC Names', 'Status', 'Catatan'];
    }

    private static function exportWidths(): array
    {
        return ['A' => 30, 'B' => 34, 'C' => 14, 'D' => 14, 'E' => 22, 'F' => 14, 'G' => 14, 'H' => 12, 'I' => 12, 'J' => 20, 'K' => 16, 'L' => 30, 'M' => 18];
    }

    private static function templateWidths(): array
    {
        return ['A' => 14, 'B' => 30, 'C' => 34, 'D' => 14, 'E' => 22, 'F' => 14, 'G' => 14, 'H' => 12, 'I' => 20, 'J' => 16, 'K' => 30];
    }

    public static function toBrowser(Collection $records, string $filename): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Task Tracker');

        $headers = self::headers();
        self::writeHeaderRow($sheet, $headers);

        foreach ($records as $i => $r) {
            $row = $i + 2;
            $sheet->setCellValue(self::cell(1, $row), $r->title);
            $sheet->setCellValue(self::cell(2, $row), $r->task_detail ?? '—');
            $sheet->setCellValue(self::cell(3, $row), $r->platform ?? '—');
            $sheet->setCellValue(self::cell(4, $row), $r->branch->name ?? '—');
            $sheet->setCellValue(self::cell(5, $row), $r->project_name ?? '—');
            $sheet->setCellValue(self::cell(6, $row), $r->start_date?->format('d M Y') ?? '—');
            $sheet->setCellValue(self::cell(7, $row), ($r->deadline_date ?? $r->scheduled_date)->format('d M Y'));
            $sheet->setCellValue(self::cell(8, $row), $r->start_date && ($r->deadline_date ?? $r->scheduled_date) ? $r->start_date->diffInDays($r->deadline_date ?? $r->scheduled_date) . ' hari' : '—');
            $sheet->setCellValue(self::cell(9, $row), strtoupper($r->priority ?? 'medium'));
            $sheet->setCellValue(self::cell(10, $row), $r->pic_names ? implode(', ', $r->pic_names) : '—');
            $sheet->setCellValue(self::cell(11, $row), strtoupper(str_replace('_', ' ', $r->status)));
            $sheet->setCellValue(self::cell(12, $row), $r->notes ?? '—');
            $sheet->setCellValue(self::cell(13, $row), $r->creator->name ?? '—');
        }

        $rowCount = $records->count() + 1;
        self::applyStyles($spreadsheet, $headers, $rowCount, self::exportWidths());
        self::addAutoFilter($sheet, $headers, $rowCount);

        $writer = new Xlsx($spreadsheet);
        return self::downloadXlsx($writer, $filename);
    }

    public static function generateTemplate(): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = self::templateHeaders();
        self::generateTemplateOpen($spreadsheet, $headers);

        $maxRow = 101;

        // --- A:Cabang dropdown ---
        $branches = Branch::where('is_active', true)->pluck('name')->toArray();
        self::branchDropdown($sheet, 'A', $maxRow, $branches);

        // --- D:Channel dropdown ---
        $platforms = ['Instagram', 'Facebook', 'TikTok', 'Twitter / X', 'Website', 'Blog', 'YouTube', 'LinkedIn', 'WhatsApp', 'Email'];
        $sheet->setDataValidation('D2:D' . $maxRow, self::listValidation($platforms));

        // --- E:Proyek dropdown ---
        $projects = LeadMaster::where('is_active', true)->orderBy('project_name')->pluck('project_name')->toArray();
        if (!empty($projects)) {
            $sheet->setDataValidation('E2:E' . $maxRow, self::listValidation($projects));
        }

        // --- F/G dates ---
        self::dateColumnStyle($sheet, 'F2:F' . $maxRow, date('Y-m-d'));
        self::dateColumnStyle($sheet, 'G2:G' . $maxRow, date('Y-m-d'));

        // --- H:Priority dropdown ---
        $sheet->setDataValidation('H2:H' . $maxRow, self::listValidation(['low', 'medium', 'high', 'urgent']));

        // --- J:Status dropdown ---
        $sheet->setDataValidation('J2:J' . $maxRow, self::listValidation(['todo', 'in_progress', 'completed', 'lost_track']));

        self::applyStyles($spreadsheet, $headers, $maxRow, self::templateWidths());

        $writer = new Xlsx($spreadsheet);
        return self::downloadXlsx($writer, 'template-task-tracker.xlsx');
    }
}
