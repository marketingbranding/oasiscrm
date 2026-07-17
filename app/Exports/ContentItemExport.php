<?php

namespace App\Exports;

use App\Exports\Concerns\ExcelStyle;
use App\Models\Branch;
use App\Models\ContentItem;
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
            'Tipe', 'Visibilitas', 'Judul', 'Detail', 'Platform', 'Cabang', 'Proyek',
            'Mulai', 'Jam Mulai', 'Deadline/Publikasi', 'Jam Selesai', 'Prioritas', 'PIC',
            'Status', 'Jenis Agenda', 'Lokasi', 'Format Konten', 'Link Aset', 'Catatan', 'Dibuat Oleh',
        ];
    }

    private static function templateHeaders(): array
    {
        return ['Cabang', 'Tipe', 'Visibilitas', 'Judul', 'Detail', 'Platform', 'Proyek', 'Mulai', 'Jam Mulai', 'Deadline/Publikasi', 'Jam Selesai', 'Prioritas', 'PIC Eksternal', 'Status', 'Jenis Agenda', 'Lokasi', 'Format Konten', 'Link Aset', 'Catatan'];
    }

    private static function exportWidths(): array
    {
        return array_fill_keys(range('A', 'T'), 18);
    }

    private static function templateWidths(): array
    {
        return array_fill_keys(range('A', 'S'), 18);
    }

    public static function toBrowser(Collection $records, string $filename): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Work Planner');

        $headers = self::headers();
        self::writeHeaderRow($sheet, $headers);

        foreach ($records as $i => $r) {
            $row = $i + 2;
            $values = [
                $r->item_type, $r->visibility, $r->title, $r->task_detail, $r->platform,
                $r->branch->name ?? null, $r->project_name, $r->start_date?->format('Y-m-d'),
                $r->start_time, $r->deadline_date?->format('Y-m-d'), $r->end_time, $r->priority,
                $r->assignees->pluck('name')->merge($r->pic_names ?? [])->join(', '), $r->status,
                $r->agenda_type, $r->location, $r->content_format, $r->asset_url, $r->notes,
                $r->creator->name ?? null,
            ];
            foreach ($values as $column => $value) {
                $sheet->setCellValue(self::cell($column + 1, $row), $value ?? '—');
            }
        }

        $rowCount = $records->count() + 1;
        self::applyStyles($spreadsheet, $headers, $rowCount, self::exportWidths());
        self::addAutoFilter($sheet, $headers, $rowCount);

        $writer = new Xlsx($spreadsheet);

        return self::downloadXlsx($writer, $filename);
    }

    public static function generateTemplate(): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = self::templateHeaders();
        self::generateTemplateOpen($spreadsheet, $headers);

        $maxRow = 101;

        // --- A:Cabang dropdown ---
        $branches = Branch::where('is_active', true)->pluck('name')->toArray();
        self::branchDropdown($sheet, 'A', $maxRow, $branches);

        $sheet->setDataValidation('B2:B'.$maxRow, self::listValidation(['task', 'agenda', 'content']));
        $sheet->setDataValidation('C2:C'.$maxRow, self::listValidation(['team', 'personal']));

        // --- F:Platform dropdown ---
        $platforms = ['Instagram', 'Facebook', 'TikTok', 'Twitter / X', 'Website', 'Blog', 'YouTube', 'LinkedIn', 'WhatsApp', 'Email'];
        $sheet->setDataValidation('F2:F'.$maxRow, self::listValidation($platforms));

        // --- E:Proyek dropdown ---
        $projects = LeadMaster::where('is_active', true)->orderBy('project_name')->pluck('project_name')->toArray();
        if (! empty($projects)) {
            $sheet->setDataValidation('G2:G'.$maxRow, self::listValidation($projects));
        }

        // --- F/G dates ---
        self::dateColumnStyle($sheet, 'H2:H'.$maxRow, date('Y-m-d'));
        self::dateColumnStyle($sheet, 'J2:J'.$maxRow, date('Y-m-d'));

        // --- H:Priority dropdown ---
        $sheet->setDataValidation('L2:L'.$maxRow, self::listValidation(['low', 'medium', 'high', 'urgent']));

        // --- J:Status dropdown ---
        $sheet->setDataValidation('N2:N'.$maxRow, self::listValidation(array_unique(array_merge(...array_values(ContentItem::STATUSES)))));

        self::applyStyles($spreadsheet, $headers, $maxRow, self::templateWidths());

        $writer = new Xlsx($spreadsheet);

        return self::downloadXlsx($writer, 'template-work-planner.xlsx');
    }
}
