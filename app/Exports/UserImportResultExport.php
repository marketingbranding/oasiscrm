<?php

namespace App\Exports;

use App\Models\UserImportBatch;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UserImportResultExport
{
    private const HEADERS = [
        'Row', 'Nama', 'Email', 'Role', 'Cabang Utama', 'Proyek Utama', 'Atasan Langsung',
        'User Creation Status', 'Invitation Status', 'Error / Warning',
    ];

    public static function download(UserImportBatch $batch): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('HASIL IMPORT USER');

        foreach (self::HEADERS as $column => $header) {
            $sheet->setCellValueExplicit([$column + 1, 1], $header, DataType::TYPE_STRING);
        }

        foreach ($batch->rows()->orderBy('row_number')->get() as $index => $row) {
            $raw = $row->raw_data;
            $messages = array_merge($row->errors ?? [], $row->warnings ?? []);
            $values = [
                (string) $row->row_number,
                $raw['name'] ?? '',
                $raw['email'] ?? '',
                $raw['role'] ?? '',
                $raw['primary_branch'] ?? '',
                $raw['primary_project'] ?? '',
                $raw['supervisor_email'] ?? '',
                self::creationLabel($row->creation_status),
                self::invitationLabel($row->invitation_status),
                implode(' | ', $messages),
            ];
            foreach ($values as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $index + 2], (string) $value, DataType::TYPE_STRING);
            }
        }

        $lastRow = max(2, $batch->total_rows + 1);
        $sheet->getStyle("A1:J{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('A1:J1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '000000']],
        ]);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:J{$lastRow}");
        foreach (['A' => 10, 'B' => 28, 'C' => 32, 'D' => 22, 'E' => 26, 'F' => 28, 'G' => 32, 'H' => 25, 'I' => 24, 'J' => 60] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getStyle("J2:J{$lastRow}")->getAlignment()->setWrapText(true);

        $directory = storage_path('app/temp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $path = tempnam($directory, 'user-import-result-');
        abort_if($path === false, 500, 'Gagal menyiapkan hasil impor.');
        try {
            (new Xlsx($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        $timestamp = ($batch->completed_at ?? now())->format('Y-m-d-His');

        return response()->download($path, "hasil-import-user-{$timestamp}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private static function creationLabel(?string $status): string
    {
        return match ($status) {
            'created' => 'CREATED',
            'failed' => 'FAILED',
            default => strtoupper((string) $status),
        };
    }

    private static function invitationLabel(?string $status): string
    {
        return match ($status) {
            'sent' => 'INVITATION SENT',
            'email_failed' => 'CREATED - EMAIL FAILED',
            'not_requested' => 'NOT REQUESTED',
            default => strtoupper((string) $status),
        };
    }
}
