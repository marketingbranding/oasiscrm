<?php

namespace Tests\Feature;

use App\Exports\UserImportTemplateExport;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserImportBatch;
use App\Models\UserImportRow;
use App\Policies\UserImportBatchPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AdminUserImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_workbook_creates_owned_preview_rows_counts_and_safe_audits(): void
    {
        $actor = $this->userForRole('superadmin');
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis Garden', 'is_active' => true]);
        $supervisor = User::factory()->create([
            'name' => 'Manager Solo', 'email' => 'manager@oasis.test',
            'role_id' => Role::where('slug', 'manager')->value('id'), 'branch_id' => $branch->id,
            'account_status' => 'active', 'password_changed_at' => now(),
        ]);

        $response = $this->postWorkbook($actor, [[
            '  BUDI   SANTOSO ', ' BUDI@EXAMPLE.TEST ', ' SALES ', ' slo ', 'Solo; SOLO',
            'Oasis Garden - Solo', ' Oasis Garden ; Oasis Garden ', ' MANAGER@OASIS.TEST ', ' invited ',
        ]], ['send_invitations' => '1'], '../rahasia-users.xlsx');

        $batch = UserImportBatch::firstOrFail();
        $response->assertRedirect(route('admin-users.import-batches.show', $batch));
        $this->assertSame('rahasia-users.xlsx', $batch->original_filename);
        $this->assertTrue($batch->send_invitations);
        $this->assertSame(UserImportBatch::STATUS_PREVIEW_READY, $batch->status);
        $this->assertSame([1, 1, 0, 0], [$batch->total_rows, $batch->valid_rows, $batch->warning_rows, $batch->error_rows]);
        $row = $batch->rows()->firstOrFail();
        $this->assertSame(2, $row->row_number);
        $this->assertSame('BUDI SANTOSO', $row->normalized_data['name']);
        $this->assertSame('budi@example.test', $row->normalized_data['email']);
        $this->assertSame([$branch->id], $row->normalized_data['branch_ids']);
        $this->assertSame([$project->id], $row->normalized_data['project_ids']);
        $this->assertSame($supervisor->id, $row->normalized_data['supervisor_user_id']);
        $this->assertSame(['user_import_uploaded', 'user_import_preview_generated'], ActivityLog::orderBy('id')->pluck('event')->all());
        $this->assertSame(['batch_id', 'total_rows', 'valid_rows', 'warning_rows', 'error_rows'], array_keys(ActivityLog::latest('id')->firstOrFail()->properties));
    }

    public function test_existing_and_within_file_duplicate_emails_are_persisted_as_errors(): void
    {
        $actor = $this->userForRole('superadmin');
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        User::factory()->create(['email' => 'existing@example.test']);

        $this->postWorkbook($actor, [
            ['Satu', 'duplicate@example.test', 'manager', 'Solo', '', '', '', '', ''],
            ['Dua', ' DUPLICATE@example.test ', 'manager', 'SLO', '', '', '', '', 'pending_invitation'],
            ['Tiga', 'existing@example.test', 'manager', 'Solo', '', '', '', '', 'invited'],
        ]);

        $batch = UserImportBatch::firstOrFail();
        $this->assertSame(UserImportBatch::STATUS_VALIDATION_FAILED, $batch->status);
        $this->assertSame(3, $batch->rows()->count());
        $this->assertSame(3, $batch->error_rows);
        $this->assertSame(2, $batch->rows()->whereJsonContains('errors', 'Email muncul lebih dari satu kali dalam file.')->count());
        $this->assertTrue($batch->rows()->get()->contains(fn (UserImportRow $row) => in_array('Email sudah digunakan oleh akun lain.', $row->errors, true)));
        $this->assertNotNull($branch);
    }

    public function test_formula_and_unsafe_formula_leading_values_become_row_errors_without_evaluation(): void
    {
        $actor = $this->userForRole('superadmin');
        Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $spreadsheet = $this->spreadsheet([['Aman', 'aman@example.test', 'manager', 'Solo', '', '', '', '', '']]);
        $sheet = $spreadsheet->getSheetByName('IMPORT USER');
        $sheet->setCellValue('A2', '=1+1');
        $sheet->setCellValueExplicit('H2', '@atasan', DataType::TYPE_STRING);

        $this->postSpreadsheet($actor, $spreadsheet);

        $row = UserImportRow::firstOrFail();
        $this->assertSame(UserImportRow::VALIDATION_ERROR, $row->validation_status);
        $this->assertCount(2, array_filter($row->errors, fn (string $error) => str_contains($error, 'tidak aman')));
        $this->assertSame('=1+1', $row->raw_data['name']);
    }

    public function test_wrong_headers_and_excess_physical_rows_are_validation_errors_without_batches(): void
    {
        $actor = $this->userForRole('superadmin');
        $wrongHeader = $this->spreadsheet([]);
        $wrongHeader->getSheetByName('IMPORT USER')->setCellValue('A1', 'Nama Pengguna');
        $this->postSpreadsheet($actor, $wrongHeader)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('user_import_batches', 0);

        $tooLong = $this->spreadsheet([]);
        $tooLong->getSheetByName('IMPORT USER')->setCellValue('A503', 'Baris berlebih');
        $this->postSpreadsheet($actor, $tooLong)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('user_import_batches', 0);
    }

    public function test_same_file_supervisor_warning_cycle_status_and_actor_scope_are_validated(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis Solo', 'is_active' => true]);
        $actor = $this->authorizedManager($branch);
        $this->postWorkbook($actor, [
            ['Bos', 'bos@example.test', 'manager', 'Solo', '', '', '', '', 'pending_invitation'],
            ['Sales', 'sales@example.test', 'sales', 'Solo', '', 'Oasis Solo', '', 'bos@example.test', 'invited'],
            ['Cycle A', 'a@example.test', 'manager', 'Solo', '', '', '', 'b@example.test', 'active'],
            ['Cycle B', 'b@example.test', 'manager', 'Solo', '', '', '', 'a@example.test', 'pending_invitation'],
            ['Pusat', 'pusat@example.test', 'pusat', 'Solo', '', '', '', '', 'inactive'],
        ]);

        $rows = UserImportRow::all()->keyBy(fn (UserImportRow $row) => $row->normalized_data['email']);
        $this->assertSame(UserImportRow::VALIDATION_WARNING, $rows['sales@example.test']->validation_status);
        $this->assertNotEmpty($rows['sales@example.test']->warnings);
        $this->assertTrue(collect($rows['a@example.test']->errors)->contains(fn (string $error) => str_contains($error, 'siklus')));
        $this->assertTrue(collect($rows['a@example.test']->errors)->contains(fn (string $error) => str_contains($error, 'Status active')));
        $this->assertTrue(collect($rows['pusat@example.test']->errors)->contains(fn (string $error) => str_contains($error, 'tidak berwenang menetapkan role')));
        $this->assertTrue(collect($rows['pusat@example.test']->errors)->contains(fn (string $error) => str_contains($error, 'Status inactive')));
    }

    public function test_preview_requires_real_xlsx_and_show_uses_persisted_owned_rows(): void
    {
        $owner = $this->userForRole('superadmin');
        $other = $this->userForRole('manager');
        $path = tempnam(sys_get_temp_dir(), 'fake-xlsx-');
        file_put_contents($path, "name,email\nFake,fake@example.test");
        try {
            $this->actingAs($owner)->post(route('admin-users.import-preview'), [
                'file' => new UploadedFile($path, 'users.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ])->assertSessionHasErrors('file');
        } finally {
            @unlink($path);
        }
        $this->assertDatabaseCount('user_import_batches', 0);

        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $this->postWorkbook($owner, [['Persisted', 'persisted@example.test', 'manager', 'Solo', '', '', '', '', '']], [
            'rows' => [['name' => 'Payload Palsu']], 'uploaded_by' => $other->id, 'status' => 'completed',
        ]);
        $batch = UserImportBatch::firstOrFail();
        $this->assertTrue($batch->uploader->is($owner));
        $this->actingAs($owner)->get(route('admin-users.import-batches.show', $batch))->assertOk()->assertSee('Persisted')->assertDontSee('Payload Palsu');
        $this->actingAs($other)->get(route('admin-users.import-batches.show', $batch))->assertForbidden();
        $this->assertNotNull($branch);
    }

    private function postWorkbook(User $actor, array $rows, array $payload = [], string $filename = 'users.xlsx')
    {
        return $this->postSpreadsheet($actor, $this->spreadsheet($rows), $payload, $filename);
    }

    private function postSpreadsheet(User $actor, Spreadsheet $spreadsheet, array $payload = [], string $filename = 'users.xlsx')
    {
        $path = tempnam(sys_get_temp_dir(), 'oasis-import-');
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

    private function spreadsheet(array $rows): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('IMPORT USER');
        $sheet->fromArray(UserImportTemplateExport::HEADERS, null, 'A1');
        foreach ($rows as $index => $row) {
            foreach ($row as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $index + 2], (string) $value, DataType::TYPE_STRING);
            }
        }

        return $spreadsheet;
    }

    private function userForRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->value('id'),
            'password_changed_at' => now(),
        ]);
    }

    private function authorizedManager(Branch $branch): User
    {
        $role = Role::where('slug', 'manager')->firstOrFail();
        $permissions = [...UserImportBatchPolicy::REQUIRED_PERMISSIONS, 'database.view_branch'];
        $role->permissions()->sync(Permission::whereIn('slug', $permissions)->pluck('id'));

        return User::factory()->create([
            'role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now(),
        ]);
    }
}
