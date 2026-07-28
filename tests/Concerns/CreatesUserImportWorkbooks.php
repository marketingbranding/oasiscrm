<?php

namespace Tests\Concerns;

use App\Exports\UserImportTemplateExport;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

trait CreatesUserImportWorkbooks
{
    protected function importSpreadsheet(array $rows, string $sheetName = 'IMPORT USER'): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet()->setTitle($sheetName);
        $sheet->fromArray(UserImportTemplateExport::HEADERS, null, 'A1');
        foreach ($rows as $index => $row) {
            foreach ($row as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $index + 2], (string) $value, DataType::TYPE_STRING);
            }
        }

        return $spreadsheet;
    }

    protected function uploadImport(User $actor, array $rows, array $payload = [], string $filename = 'users.xlsx')
    {
        return $this->uploadImportSpreadsheet($actor, $this->importSpreadsheet($rows), $payload, $filename);
    }

    protected function uploadImportSpreadsheet(User $actor, Spreadsheet $spreadsheet, array $payload = [], string $filename = 'users.xlsx')
    {
        $path = tempnam(sys_get_temp_dir(), 'oasis-user-import-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            return $this->actingAs($actor)->post(route('admin-users.import-preview'), [
                ...$payload,
                'file' => new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ]);
        } finally {
            @unlink($path);
        }
    }

    protected function importActor(string $role = 'superadmin'): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'password_changed_at' => now(),
        ]);
    }
}
