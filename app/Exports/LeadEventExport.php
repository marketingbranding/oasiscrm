<?php

namespace App\Exports;

use App\Exports\Concerns\ExcelStyle;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\LeadSource;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class LeadEventExport
{
    use ExcelStyle;

    private static function headers(): array
    {
        return [
            'Event ID', 'Cabang', 'Proyek', 'Sumber Lead',
            'Tgl Mulai', 'Tgl Selesai', 'Anggaran', 'Cost/Lead', 'Status', 'Catatan',
        ];
    }

    private static function templateHeaders(): array
    {
        return ['Cabang', 'Event ID', 'Proyek', 'Sumber Lead', 'Tgl Mulai', 'Tgl Selesai', 'Anggaran', 'Status', 'Catatan'];
    }

    private static function exportWidths(): array
    {
        return [
            'A' => 14, 'B' => 14, 'C' => 22, 'D' => 14,
            'E' => 14, 'F' => 14, 'G' => 14, 'H' => 14, 'I' => 12, 'J' => 30,
        ];
    }

    private static function templateWidths(): array
    {
        return ['A' => 14, 'B' => 14, 'C' => 22, 'D' => 14, 'E' => 14, 'F' => 14, 'G' => 14, 'H' => 12, 'I' => 30];
    }

    public static function toBrowser(Collection $records, string $filename): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Lead Events');

        $headers = self::headers();
        self::writeHeaderRow($sheet, $headers);

        foreach ($records as $i => $r) {
            $row = $i + 2;
            $sheet->setCellValue(self::cell(1, $row), $r->event_id ?? '—');
            $sheet->setCellValue(self::cell(2, $row), $r->branch->name ?? '—');
            $sheet->setCellValue(self::cell(3, $row), $r->project_name);
            $sheet->setCellValue(self::cell(4, $row), $r->lead_source);
            $sheet->setCellValue(self::cell(5, $row), $r->start_date->format('d M Y'));
            $sheet->setCellValue(self::cell(6, $row), $r->end_date?->format('d M Y') ?? '—');
            $sheet->setCellValue(self::cell(7, $row), $r->total_budget ? 'Rp' . number_format($r->total_budget, 0, ',', '.') : '—');
            $totalLeads = $r->daily_logs_sum_leads_count ?? 0;
            $costPerLead = $totalLeads > 0 ? $r->total_budget / $totalLeads : null;
            $sheet->setCellValue(self::cell(8, $row), $costPerLead !== null ? 'Rp' . number_format($costPerLead, 0, ',', '.') : '—');
            $sheet->setCellValue(self::cell(9, $row), strtoupper($r->status));
            $sheet->setCellValue(self::cell(10, $row), $r->notes ?? '—');
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

        // --- D:Sumber Lead dropdown ---
        $sources = LeadSource::where('is_active', true)->pluck('name')->toArray();
        if (!empty($sources)) {
            $sheet->setDataValidation('D2:D' . $maxRow, self::listValidation($sources));
        }

        // --- E:Tgl Mulai date format ---
        self::dateColumnStyle($sheet, 'E2:F' . $maxRow, date('Y-m-d'));

        // --- H:Status dropdown ---
        $sheet->setDataValidation('H2:H' . $maxRow, self::listValidation(['berlangsung', 'selesai']));

        // --- C:Proyek dropdown ---
        $projects = LeadMaster::where('is_active', true)->orderBy('project_name')->pluck('project_name')->toArray();
        if (!empty($projects)) {
            $sheet->setDataValidation('C2:C' . $maxRow, self::listValidation($projects));
        }

        self::applyStyles($spreadsheet, $headers, $maxRow, self::templateWidths());

        $writer = new Xlsx($spreadsheet);
        self::downloadXlsx($writer, 'template-lead-events.xlsx');
    }
}
