<?php

namespace App\Exports;

use App\Exports\Concerns\ExcelStyle;
use App\Models\Branch;
use App\Models\LeadEvent;
use App\Models\LeadMaster;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class LeadDailyExport
{
    use ExcelStyle;

    private static function headers(): array
    {
        return [
            'Tanggal', 'Event ID', 'Proyek', 'Cabang',
            'Hari Ke', 'Leads', 'Kumulatif', 'Achieve %',
        ];
    }

    private static function templateHeaders(): array
    {
        return ['Cabang', 'Tanggal', 'Event ID', 'Proyek', 'Hari Ke', 'Leads', 'Kumulatif', 'Achieve %'];
    }

    private static function widths(): array
    {
        return ['A' => 14, 'B' => 14, 'C' => 22, 'D' => 14, 'E' => 10, 'F' => 10, 'G' => 14, 'H' => 14];
    }

    public static function toBrowser(Collection $records, string $filename): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Lead Harian');

        $headers = self::headers();
        self::writeHeaderRow($sheet, $headers);

        foreach ($records as $i => $r) {
            $row = $i + 2;
            $sheet->setCellValue(self::cell(1, $row), $r->date->format('d M Y'));
            $sheet->setCellValue(self::cell(2, $row), $r->leadEvent->event_id ?? '#' . $r->lead_event_id);
            $sheet->setCellValue(self::cell(3, $row), $r->leadEvent->project_name);
            $sheet->setCellValue(self::cell(4, $row), $r->branch->name ?? '—');
            $sheet->setCellValue(self::cell(5, $row), $r->day_number ?? '—');
            $sheet->setCellValue(self::cell(6, $row), $r->leads_count);
            $sheet->setCellValue(self::cell(7, $row), $r->cumulative_leads);
            $sheet->setCellValue(self::cell(8, $row), $r->achievement_pct !== null ? number_format($r->achievement_pct, 0) . '%' : '—');
        }

        $rowCount = $records->count() + 1;
        self::applyStyles($spreadsheet, $headers, $rowCount, self::widths());
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

        // --- B:Tanggal date format ---
        self::dateColumnStyle($sheet, 'B2:B' . $maxRow, date('Y-m-d'));

        // --- C:Event ID dropdown ---
        $events = LeadEvent::with('branch')
            ->orderBy('event_id')
            ->get()
            ->map(fn($e) => ($e->event_id ?? '#' . $e->id) . ' — ' . $e->project_name . ' (' . ($e->branch->name ?? '') . ')')
            ->toArray();
        if (!empty($events)) {
            $sheet->setDataValidation('C2:C' . $maxRow, self::listValidation($events));
        }

        // --- D:Proyek dropdown ---
        $projects = LeadMaster::where('is_active', true)->orderBy('project_name')->pluck('project_name')->toArray();
        if (!empty($projects)) {
            $sheet->setDataValidation('D2:D' . $maxRow, self::listValidation($projects));
        }

        self::applyStyles($spreadsheet, $headers, $maxRow, self::widths());

        $writer = new Xlsx($spreadsheet);
        self::downloadXlsx($writer, 'template-lead-harian.xlsx');
    }
}
