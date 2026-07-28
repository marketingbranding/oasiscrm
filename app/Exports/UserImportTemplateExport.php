<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UserImportTemplateExport
{
    public const FILENAME = 'template-import-user-oasis.xlsx';

    public const EXAMPLE_MARKER = 'CONTOH - JANGAN DIIMPORT';

    public const ROLE_SLUGS = ['sales', 'sales_coordinator', 'supervisor', 'manager', 'branch_manager', 'pusat'];

    public const HEADERS = [
        'Nama', 'Email', 'Role', 'Cabang Utama', 'Cabang Tambahan', 'Proyek Utama',
        'Proyek Tambahan', 'Atasan Langsung', 'Status',
    ];

    public static function download(Collection $roles, Collection $branches, Collection $projects): BinaryFileResponse
    {
        $spreadsheet = self::workbook($roles, $branches, $projects);
        $directory = storage_path('app/temp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $tempPath = tempnam($directory, 'user-import-');
        abort_if($tempPath === false, 500, 'Gagal menyiapkan template impor.');

        try {
            (new Xlsx($spreadsheet))->save($tempPath);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return response()->download($tempPath, self::FILENAME, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public static function workbook(Collection $roles, Collection $branches, Collection $projects): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        self::writeImportSheet($spreadsheet->getActiveSheet(), $branches);
        self::writeReferenceSheet($spreadsheet->createSheet(), $roles, $branches, $projects);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private static function writeImportSheet(Worksheet $sheet, Collection $branches): void
    {
        $sheet->setTitle('IMPORT USER');
        foreach (self::HEADERS as $column => $header) {
            self::text($sheet, $column + 1, 1, $header);
        }

        $example = [
            self::EXAMPLE_MARKER, 'contoh@oasis.test', 'sales', 'Cabang Contoh',
            'Cabang A; Cabang B', 'Proyek Contoh', 'Proyek A; Proyek B',
            'atasan@oasis.test', 'pending_invitation',
        ];
        foreach ($example as $column => $value) {
            self::text($sheet, $column + 1, 2, $value);
        }

        $sheet->getStyle('A2:I501')->getNumberFormat()->setFormatCode('@');
        self::blackHeader($sheet, 'A1:I1');
        $sheet->getStyle('A1:I501')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('A2:I2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF3B0');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:I501');

        foreach (['A' => 31, 'B' => 31, 'C' => 22, 'D' => 25, 'E' => 34, 'F' => 28, 'G' => 38, 'H' => 31, 'I' => 22] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->setDataValidation('C2:C501', self::validation("'REFERENSI'!\$A\$3:\$A\$8"));
        $sheet->setDataValidation('I2:I501', self::validation("'REFERENSI'!\$D\$3:\$D\$5"));
        $sheet->setDataValidation('D2:D501', self::validation("'REFERENSI'!\$G\$3:\$G\$".max(3, $branches->count() + 2)));
    }

    private static function writeReferenceSheet(Worksheet $sheet, Collection $roles, Collection $branches, Collection $projects): void
    {
        $sheet->setTitle('REFERENSI');
        self::text($sheet, 1, 1, 'PERAN YANG DIIZINKAN');
        self::text($sheet, 1, 2, 'SLUG');
        self::text($sheet, 2, 2, 'LABEL');
        foreach (self::ROLE_SLUGS as $index => $slug) {
            $role = $roles->firstWhere('slug', $slug);
            self::text($sheet, 1, $index + 3, $slug);
            self::text($sheet, 2, $index + 3, $role?->name ?? str($slug)->replace('_', ' ')->title()->toString());
        }

        self::text($sheet, 4, 1, 'STATUS YANG DIIZINKAN');
        self::text($sheet, 4, 2, 'VALUE');
        self::text($sheet, 5, 2, 'CATATAN');
        foreach ([
            ['pending_invitation', 'Simpan sebagai draft undangan'],
            ['invited', 'Buat dan kirim undangan'],
            ['active', 'BELUM DIDUKUNG - tidak akan diaktifkan langsung'],
        ] as $index => $status) {
            self::text($sheet, 4, $index + 3, $status[0]);
            self::text($sheet, 5, $index + 3, $status[1]);
        }

        self::text($sheet, 7, 1, 'CABANG AKTIF');
        self::text($sheet, 7, 2, 'NAMA');
        self::text($sheet, 8, 2, 'KODE');
        foreach ($branches->values() as $index => $branch) {
            self::text($sheet, 7, $index + 3, $branch->name);
            self::text($sheet, 8, $index + 3, $branch->code);
        }

        self::text($sheet, 10, 1, 'PROYEK AKTIF PER CABANG');
        self::text($sheet, 10, 2, 'CABANG');
        self::text($sheet, 11, 2, 'PROYEK');
        foreach ($projects->values() as $index => $project) {
            self::text($sheet, 10, $index + 3, $project->branch?->name ?? '-');
            self::text($sheet, 11, $index + 3, $project->project_name);
        }

        self::text($sheet, 13, 1, 'PETUNJUK');
        foreach ([
            'Isi satu pengguna per baris pada sheet IMPORT USER.',
            'Gunakan slug pada kolom Role dan nama persis seperti daftar referensi untuk cabang/proyek.',
            'Pisahkan Cabang Tambahan dan Proyek Tambahan dengan titik koma (;).',
            'Contoh cabang tambahan: Cabang Solo; Cabang Pati',
            'Contoh proyek tambahan: Oasis Residence; Oasis Garden',
            'Atasan Langsung diisi dengan email pengguna aktif.',
            'Baris bertanda '.self::EXAMPLE_MARKER.' hanya contoh dan tidak akan diimpor.',
        ] as $index => $instruction) {
            self::text($sheet, 13, $index + 2, $instruction);
        }

        foreach (['A2:B8', 'D2:E5', 'G2:H'.max(3, $branches->count() + 2), 'J2:K'.max(3, $projects->count() + 2)] as $range) {
            $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
        foreach (['A1:B2', 'D1:E2', 'G1:H2', 'J1:K2', 'M1:M1'] as $range) {
            self::blackHeader($sheet, $range);
        }
        foreach (['A' => 24, 'B' => 28, 'D' => 24, 'E' => 45, 'G' => 28, 'H' => 16, 'J' => 28, 'K' => 34, 'M' => 85] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getStyle('M1:M9')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->freezePane('A3');
    }

    private static function validation(string $range): DataValidation
    {
        $validation = new DataValidation;
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowDropDown(false);
        $validation->setFormula1('='.$range);

        return $validation;
    }

    private static function text(Worksheet $sheet, int $column, int $row, string $value): void
    {
        $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($column).$row, $value, DataType::TYPE_STRING);
    }

    private static function blackHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Times New Roman'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '000000']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }
}
