<?php

namespace Tests\Feature;

use App\Exports\UserImportTemplateExport;
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
use Tests\Concerns\CreatesUserImportWorkbooks;
use Tests\TestCase;

class AdminUserImportValidationCoverageTest extends TestCase
{
    use CreatesUserImportWorkbooks;
    use RefreshDatabase;

    public function test_upload_boundary_rejects_absent_oversized_malformed_wrong_sheet_and_over_500_data_rows(): void
    {
        $actor = $this->importActor();

        $this->actingAs($actor)->post(route('admin-users.import-preview'))->assertSessionHasErrors('file');
        $this->actingAs($actor)->post(route('admin-users.import-preview'), [
            'file' => UploadedFile::fake()->create('users.xlsx', 5121, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ])->assertSessionHasErrors('file');
        $this->actingAs($actor)->post(route('admin-users.import-preview'), [
            'file' => UploadedFile::fake()->createWithContent('users.xlsx', 'not a zip workbook'),
        ])->assertSessionHasErrors('file');
        $this->uploadImportSpreadsheet($actor, $this->importSpreadsheet([], 'USERS'))->assertSessionHasErrors('file');

        $rows = [];
        foreach (range(1, 501) as $number) {
            $rows[] = ["User {$number}", "limit{$number}@example.test", 'manager', 'Solo', '', '', '', '', ''];
        }
        $this->uploadImport($actor, $rows)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('user_import_batches', 0);
    }

    public function test_all_six_roles_accept_case_insensitive_input_while_superadmin_and_inactive_role_are_rejected(): void
    {
        $actor = $this->importActor();
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis Solo', 'is_active' => true]);
        $rows = collect(UserImportTemplateExport::ROLE_SLUGS)->map(fn (string $role, int $index) => [
            "Role {$index}", "role{$index}@example.test", strtoupper($role), ' slo ', '',
            $role === 'sales' ? 'Oasis Solo' : '', '', '', ' PENDING_INVITATION ',
        ])->all();
        $rows[] = ['Root', 'root@example.test', 'superadmin', 'Solo', '', '', '', '', ''];
        Role::query()->where('slug', 'staff')->update(['is_active' => false]);
        $rows[] = ['Inactive Role', 'inactive-role@example.test', 'staff', 'Solo', '', '', '', '', ''];

        $this->uploadImport($actor, $rows);
        $byEmail = UserImportRow::all()->keyBy(fn (UserImportRow $row) => $row->normalized_data['email']);

        foreach (range(0, 5) as $index) {
            $this->assertSame(UserImportRow::VALIDATION_VALID, $byEmail["role{$index}@example.test"]->validation_status);
            $this->assertSame(UserImportTemplateExport::ROLE_SLUGS[$index], $byEmail["role{$index}@example.test"]->normalized_data['role_slug']);
        }
        $this->assertSame(UserImportRow::VALIDATION_ERROR, $byEmail['root@example.test']->validation_status);
        $this->assertSame(UserImportRow::VALIDATION_ERROR, $byEmail['inactive-role@example.test']->validation_status);
    }

    public function test_branch_and_project_validation_covers_inactive_unknown_and_project_outside_assigned_branches(): void
    {
        $actor = $this->importActor();
        $solo = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $inactiveBranch = Branch::create(['name' => 'Mati', 'code' => 'OFF', 'is_active' => false]);
        $other = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $solo->id, 'project_name' => 'Solo Aktif', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $solo->id, 'project_name' => 'Solo Mati', 'is_active' => false]);
        LeadMaster::create(['branch_id' => $other->id, 'project_name' => 'Pati Aktif', 'is_active' => true]);

        $this->uploadImport($actor, [
            ['Branch Mati', 'branch-off@example.test', 'manager', $inactiveBranch->name, '', '', '', '', ''],
            ['Branch Unknown', 'branch-unknown@example.test', 'manager', 'Tidak Ada', '', '', '', '', ''],
            ['Project Mati', 'project-off@example.test', 'manager', 'Solo', '', 'Solo Mati', '', '', ''],
            ['Project Outside', 'project-out@example.test', 'sales', 'Solo', '', 'Pati Aktif', '', '', ''],
        ]);

        $rows = UserImportRow::all();
        $this->assertTrue($rows->every(fn (UserImportRow $row) => $row->validation_status === UserImportRow::VALIDATION_ERROR));
        $messages = $rows->flatMap->errors->implode(' ');
        $this->assertStringContainsString('cabang aktif', mb_strtolower($messages));
        $this->assertStringContainsString('proyek utama', mb_strtolower($messages));
    }

    public function test_branch_scoped_importer_cannot_assign_a_user_outside_their_branch_or_project_scope(): void
    {
        $solo = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $pati = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $pati->id, 'project_name' => 'Oasis Pati', 'is_active' => true]);
        $role = Role::where('slug', 'manager')->firstOrFail();
        $role->permissions()->sync(Permission::whereIn('slug', [
            ...UserImportBatchPolicy::REQUIRED_PERMISSIONS, 'database.view_branch',
        ])->pluck('id'));
        $actor = User::factory()->create([
            'role_id' => $role->id, 'branch_id' => $solo->id, 'password_changed_at' => now(),
        ]);

        $this->uploadImport($actor, [[
            'Di Luar Scope', 'outside-scope@example.test', 'sales', 'Pati', '', 'Oasis Pati', '', '', '',
        ]]);
        $row = UserImportRow::firstOrFail();

        $this->assertSame(UserImportRow::VALIDATION_ERROR, $row->validation_status);
        $this->assertTrue(collect($row->errors)->contains(fn (string $error) => str_contains($error, 'tidak berwenang')));
    }

    public function test_semicolon_lists_are_trimmed_case_insensitively_and_deduplicated(): void
    {
        $actor = $this->importActor();
        $solo = Branch::create(['name' => 'Solo Raya', 'code' => 'SLO', 'is_active' => true]);
        $pati = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $main = LeadMaster::create(['branch_id' => $solo->id, 'project_name' => 'Oasis Solo', 'is_active' => true]);
        $extra = LeadMaster::create(['branch_id' => $pati->id, 'project_name' => 'Oasis Pati', 'is_active' => true]);

        $this->uploadImport($actor, [[
            '  Nama   Bersih ', ' CLEAN@EXAMPLE.TEST ', ' SALES ', ' slo ', ' pati ; PTI ; ',
            ' oasis solo ', ' Oasis Pati ; oasis pati ', '', '',
        ]]);
        $row = UserImportRow::firstOrFail();

        $this->assertSame(UserImportRow::VALIDATION_VALID, $row->validation_status);
        $this->assertSame('Nama Bersih', $row->normalized_data['name']);
        $this->assertSame('clean@example.test', $row->normalized_data['email']);
        $this->assertEqualsCanonicalizing([$solo->id, $pati->id], $row->normalized_data['branch_ids']);
        $this->assertSame([$main->id, $extra->id], $row->normalized_data['project_ids']);
    }

    public function test_supervisor_missing_self_lower_inactive_outside_scope_cycle_and_same_batch_warning(): void
    {
        $actor = $this->importActor();
        $solo = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $pati = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        User::factory()->create(['email' => 'inactive.boss@example.test', 'role_id' => Role::where('slug', 'manager')->value('id'), 'branch_id' => $solo->id, 'account_status' => 'inactive']);
        User::factory()->create(['email' => 'pati.boss@example.test', 'role_id' => Role::where('slug', 'manager')->value('id'), 'branch_id' => $pati->id, 'account_status' => 'active']);
        User::factory()->create(['email' => 'sales.boss@example.test', 'role_id' => Role::where('slug', 'sales')->value('id'), 'branch_id' => $solo->id, 'account_status' => 'active']);

        $this->uploadImport($actor, [
            ['Missing', 'missing@example.test', 'manager', 'Solo', '', '', '', 'none@example.test', ''],
            ['Self', 'self@example.test', 'manager', 'Solo', '', '', '', 'self@example.test', ''],
            ['Lower', 'lower@example.test', 'manager', 'Solo', '', '', '', 'sales.boss@example.test', ''],
            ['Inactive', 'inactive@example.test', 'sales_coordinator', 'Solo', '', '', '', 'inactive.boss@example.test', ''],
            ['Outside', 'outside@example.test', 'sales_coordinator', 'Solo', '', '', '', 'pati.boss@example.test', ''],
            ['Cycle A', 'cycle-a@example.test', 'manager', 'Solo', '', '', '', 'cycle-b@example.test', ''],
            ['Cycle B', 'cycle-b@example.test', 'manager', 'Solo', '', '', '', 'cycle-a@example.test', ''],
            ['Batch Boss', 'batch-boss@example.test', 'manager', 'Solo', '', '', '', '', ''],
            ['Batch Child', 'batch-child@example.test', 'sales_coordinator', 'Solo', '', '', '', 'batch-boss@example.test', ''],
        ]);
        $rows = UserImportRow::all()->keyBy(fn (UserImportRow $row) => $row->normalized_data['email']);

        foreach (['missing', 'self', 'lower', 'inactive', 'outside', 'cycle-a', 'cycle-b'] as $email) {
            $this->assertSame(UserImportRow::VALIDATION_ERROR, $rows["{$email}@example.test"]->validation_status);
        }
        $this->assertStringContainsString('tidak ditemukan', implode(' ', $rows['missing@example.test']->errors));
        $this->assertStringContainsString('dirinya sendiri', implode(' ', $rows['self@example.test']->errors));
        $this->assertStringContainsString('tingkat kewenangan', implode(' ', $rows['lower@example.test']->errors));
        $this->assertStringContainsString('pengguna aktif', implode(' ', $rows['inactive@example.test']->errors));
        $this->assertStringContainsString('berbagi cabang atau proyek', implode(' ', $rows['outside@example.test']->errors));
        $this->assertSame(UserImportRow::VALIDATION_WARNING, $rows['batch-child@example.test']->validation_status);
        $this->assertStringContainsString('file ini', implode(' ', $rows['batch-child@example.test']->warnings));
    }

    public function test_status_defaults_to_pending_and_rejects_active_inactive_suspended_and_unknown(): void
    {
        $actor = $this->importActor();
        Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $this->uploadImport($actor, [
            ['Default', 'default@example.test', 'manager', 'Solo', '', '', '', '', ''],
            ['Active', 'active@example.test', 'manager', 'Solo', '', '', '', '', 'active'],
            ['Inactive', 'status-inactive@example.test', 'manager', 'Solo', '', '', '', '', 'inactive'],
            ['Suspended', 'suspended@example.test', 'manager', 'Solo', '', '', '', '', 'suspended'],
            ['Unknown', 'unknown@example.test', 'manager', 'Solo', '', '', '', '', 'enabled'],
        ]);
        $rows = UserImportRow::all()->keyBy(fn (UserImportRow $row) => $row->normalized_data['email']);

        $this->assertSame('pending_invitation', $rows['default@example.test']->normalized_data['status']);
        $this->assertSame(UserImportRow::VALIDATION_VALID, $rows['default@example.test']->validation_status);
        foreach (['active', 'status-inactive', 'suspended', 'unknown'] as $email) {
            $this->assertSame(UserImportRow::VALIDATION_ERROR, $rows["{$email}@example.test"]->validation_status);
        }
        $this->assertSame(UserImportBatch::STATUS_VALIDATION_FAILED, UserImportBatch::firstOrFail()->status);
    }
}
