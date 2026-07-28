<?php

namespace Tests\Feature;

use App\Exports\UserImportTemplateExport;
use App\Models\Branch;
use App\Models\Changelog;
use App\Models\LeadMaster;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserImportBatch;
use App\Models\UserImportRow;
use App\Policies\UserImportBatchPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use Tests\TestCase;

class AdminUserImportFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staging_schema_models_relationships_and_casts(): void
    {
        $this->assertTrue(Schema::hasColumns('user_import_batches', [
            'original_filename', 'uploaded_by', 'status', 'total_rows', 'valid_rows', 'warning_rows',
            'error_rows', 'send_invitations', 'confirmed_at', 'completed_at', 'expires_at',
        ]));
        $this->assertTrue(Schema::hasColumns('user_import_rows', [
            'batch_id', 'row_number', 'raw_data', 'normalized_data', 'validation_status', 'errors',
            'warnings', 'created_user_id', 'invitation_status', 'creation_status',
        ]));

        $uploader = $this->authorizedUser('manager');
        $createdUser = User::factory()->create();
        $batch = UserImportBatch::create([
            'original_filename' => 'users.xlsx',
            'uploaded_by' => $uploader->id,
            'status' => UserImportBatch::STATUS_DRAFT,
            'send_invitations' => true,
            'expires_at' => now()->addDay(),
        ]);
        $row = $batch->rows()->create([
            'row_number' => 2,
            'raw_data' => ['Nama' => 'Budi'],
            'normalized_data' => ['name' => 'Budi'],
            'validation_status' => UserImportRow::VALIDATION_WARNING,
            'warnings' => ['Cabang tambahan kosong'],
            'created_user_id' => $createdUser->id,
        ]);

        $this->assertTrue($batch->send_invitations);
        $this->assertNotNull($batch->expires_at);
        $this->assertSame(['Nama' => 'Budi'], $row->raw_data);
        $this->assertSame(['Cabang tambahan kosong'], $row->warnings);
        $this->assertTrue($batch->uploader->is($uploader));
        $this->assertTrue($row->createdUser->is($createdUser));

        $createdUser->delete();
        $this->assertNull($row->fresh()->created_user_id);
        $batch->delete();
        $this->assertDatabaseMissing('user_import_rows', ['id' => $row->id]);
    }

    public function test_all_permissions_middleware_and_batch_policy_enforce_and_owner_scope(): void
    {
        $owner = $this->authorizedUser('manager');
        $other = $this->authorizedUser('manager');
        $partial = $this->authorizedUser('staff', array_slice(UserImportBatchPolicy::REQUIRED_PERMISSIONS, 0, -1));
        $superadmin = $this->userForRole('superadmin');
        $batch = UserImportBatch::create([
            'original_filename' => 'owned.xlsx',
            'uploaded_by' => $owner->id,
            'status' => UserImportBatch::STATUS_DRAFT,
        ]);

        $this->actingAs($partial)->get(route('admin-users.import'))->assertForbidden();
        $this->actingAs($owner)->get(route('admin-users.import'))->assertOk();
        $this->actingAs($owner)->get(route('admin-users.import-batches.show', $batch))->assertOk();
        $this->actingAs($other)->get(route('admin-users.import-batches.show', $batch))->assertForbidden();
        $this->actingAs($superadmin)->get(route('admin-users.import-batches.show', $batch))->assertOk();

        $this->actingAs($other)->get(route('admin-users.import-history'))->assertOk()->assertDontSee('owned.xlsx');
        $this->actingAs($superadmin)->get(route('admin-users.import-history'))->assertOk()->assertSee('owned.xlsx');
    }

    public function test_template_contains_exact_structure_references_text_cells_and_validations(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis Solo', 'is_active' => true]);
        $roles = Role::query()->whereIn('slug', UserImportTemplateExport::ROLE_SLUGS)->get();
        $workbook = UserImportTemplateExport::workbook($roles, collect([$branch]), collect([$project->load('branch')]));

        $this->assertSame(['IMPORT USER', 'REFERENSI'], $workbook->getSheetNames());
        $import = $workbook->getSheetByName('IMPORT USER');
        $reference = $workbook->getSheetByName('REFERENSI');
        $this->assertSame(UserImportTemplateExport::HEADERS, $import->rangeToArray('A1:I1')[0]);
        $this->assertSame(UserImportTemplateExport::EXAMPLE_MARKER, $import->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $import->getCell('A2')->getDataType());
        $this->assertSame(UserImportTemplateExport::ROLE_SLUGS, array_column($reference->rangeToArray('A3:A8'), 0));
        $this->assertNotContains('superadmin', array_column($reference->rangeToArray('A3:A8'), 0));
        $this->assertSame('Solo', $reference->getCell('G3')->getValue());
        $this->assertSame('SLO', $reference->getCell('H3')->getValue());
        $this->assertSame('Solo', $reference->getCell('J3')->getValue());
        $this->assertSame('Oasis Solo', $reference->getCell('K3')->getValue());
        $this->assertStringContainsString('BELUM DIDUKUNG', $reference->getCell('E5')->getValue());

        foreach (['C2' => '$A$3:$A$8', 'D501' => '$G$3:$G$3', 'I501' => '$D$3:$D$5'] as $cell => $expectedRange) {
            $validation = $import->getCell($cell)->getDataValidation();
            $this->assertSame(DataValidation::TYPE_LIST, $validation->getType());
            $this->assertStringContainsString($expectedRange, $validation->getFormula1());
        }
        $this->assertSame('A2', $import->getFreezePane());
        $this->assertSame('A1:I501', $import->getAutoFilter()->getRange());
        $workbook->disconnectWorksheets();
    }

    public function test_import_routes_precede_dynamic_user_route_and_use_exact_and_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $dynamicIndex = $routes->search(fn ($route) => $route->uri() === 'admin-users/{admin_user}' && in_array('GET', $route->methods(), true));
        $requiredMiddleware = 'permissions.all:'.implode(',', UserImportBatchPolicy::REQUIRED_PERMISSIONS);

        foreach (['admin-users.import', 'admin-users.import-preview', 'admin-users.import-template', 'admin-users.import-history', 'admin-users.import-batches.show'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertLessThan($dynamicIndex, $routes->search(fn ($candidate) => $candidate === $route));
            $this->assertContains($requiredMiddleware, $route->gatherMiddleware());
        }
        $this->assertContains('POST', Route::getRoutes()->getByName('admin-users.import-preview')->methods());
    }

    public function test_bulk_import_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Persiapan impor pengguna secara massal';
        $actor = $this->authorizedUser('manager');

        $this->assertSame(1, Changelog::query()->whereNull('version')->where('title', $title)->count());
        $this->actingAs($actor)->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }

    private function authorizedUser(string $roleSlug, ?array $permissions = null): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $permissionIds = Permission::query()->whereIn('slug', $permissions ?? UserImportBatchPolicy::REQUIRED_PERMISSIONS)->pluck('id');
        $role->permissions()->sync($permissionIds);

        return User::factory()->create(['role_id' => $role->id, 'password_changed_at' => now()]);
    }

    private function userForRole(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'password_changed_at' => now(),
        ]);
    }
}
