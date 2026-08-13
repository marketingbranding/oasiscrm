<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UserImportCredentialExport
{
    private const HEADERS = ['Nama', 'Email', 'Role', 'Cabang Utama', 'Password Sementara'];

    public static function create(array $credentials): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('KREDENSIAL USER');

        foreach (self::HEADERS as $column => $header) {
            $sheet->setCellValueExplicit([$column + 1, 1], $header, DataType::TYPE_STRING);
        }

        foreach ($credentials as $index => $credential) {
            $values = [
                $credential['name'] ?? '',
                $credential['email'] ?? '',
                $credential['role'] ?? '',
                $credential['primary_branch'] ?? '',
                $credential['temporary_password'] ?? '',
            ];

            foreach ($values as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $index + 2], (string) $value, DataType::TYPE_STRING);
            }
        }

        $directory = storage_path('app/temp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $path = tempnam($directory, 'user-import-credentials-');
        abort_if($path === false, 500, 'Gagal menyiapkan kredensial pengguna.');

        try {
            (new Xlsx($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $path;
    }

    public static function download(string $path, int $batchId): BinaryFileResponse
    {
        return response()->download($path, "kredensial-user-batch-{$batchId}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }
}
