<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\Customer;
use App\Models\LeadMaster;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ConsumerDatabaseWriteService;
use App\Services\DatabaseModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConsumerDatabaseInlineEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_name_updates_shared_customer_completeness_and_safe_audit(): void
    {
        [$user, $application, $customer] = $this->context();
        $other = ConsumerApplication::create(['customer_id' => $customer->id, 'branch_id' => $application->branch_id, 'project_id' => $application->project_id, 'application_status' => 'active', 'current_stage' => 'akad']);

        $response = $this->patchCell($user, $application, 'customer_name', '  Nama Baru  ', $this->token($customer))->assertOk()
            ->assertJsonPath('value', 'Nama Baru')->assertJsonPath('display', 'Nama Baru')->assertJsonStructure(['write_token', 'application_updated_at', 'customer_updated_at']);

        $this->assertSame('Nama Baru', $customer->fresh()->name);
        $this->assertSame('Data Belum Lengkap', $application->fresh()->source_completeness_status);
        $this->assertSame('Data Belum Lengkap', $other->fresh()->source_completeness_status);
        $this->assertSame('akad', $other->fresh()->current_stage);
        $audit = ActivityLog::where('event', 'consumer_database_cell_updated')->sole();
        $this->assertSame([$application->id, $other->id], $audit->properties['affected_application_ids']);
        $this->assertSame(2, $audit->properties['affected_application_count']);
        $this->assertSame('Nama Baru', $audit->properties['after']);
        $this->assertNotEmpty($response->json('write_token'));
    }

    public function test_phone_update_masks_audit_and_conflict_never_leaks_plaintext(): void
    {
        [$user, $application, $customer] = $this->context();
        $oldToken = $this->token($customer);
        $this->patchCell($user, $application, 'phone', '081234567890', $oldToken)->assertOk();
        $properties = ActivityLog::where('event', 'consumer_database_cell_updated')->sole()->properties;
        $this->assertSame('********7890', $properties['after']['masked']);
        $this->assertStringNotContainsString('081234567890', json_encode($properties));

        $response = $this->patchCell($user, $application, 'phone', '089999999999', now()->subDay()->toISOString())->assertConflict()
            ->assertJsonPath('code', 'record_modified')->assertJsonPath('current_value', null)
            ->assertJsonStructure(['expected_updated_at', 'current_updated_at', 'current_updated_label', 'reload_url']);
        $this->assertStringNotContainsString('081234567890', $response->getContent());
        $this->assertSame('081234567890', $customer->fresh()->phone);
    }

    public function test_application_fields_accept_notes_and_status_cash_select_values(): void
    {
        [$user, $application] = $this->context();
        $token = $this->token($application);
        $this->patchCell($user, $application, 'notes', '  Catatan aman  ', $token)->assertOk()->assertJsonPath('value', 'Catatan aman');
        $audit = ActivityLog::latest('id')->first()->properties;
        $this->assertSame(['is_null' => false, 'length' => 12], $audit['after']);
        $this->assertStringNotContainsString('Catatan aman', json_encode($audit));

        foreach ([['1', true, 'Ya'], ['0', false, 'Tidak'], ['', null, '—']] as [$input, $stored, $display]) {
            $response = $this->patchCell($user, $application->fresh(), 'status_cash', $input, $this->token($application->fresh()))->assertOk()->assertJsonPath('display', $display);
            $this->assertSame($stored, $application->fresh()->status_cash);
            $this->assertNotEmpty($response->json('write_token'));
        }
    }

    public function test_authorization_scope_and_supplemental_roles_do_not_escalate(): void
    {
        [$superadmin, $application] = $this->context();
        $viewOnly = $this->userWithPermissions(['consumer_progress.view_branch'], $application, 'manager');
        $this->patchCell($viewOnly, $application, 'notes', 'x', $this->token($application))->assertForbidden();

        [$foreignUser, $foreignApplication] = $this->scopedContext('consumer_progress.manage_branch');
        $this->patchCell($foreignUser, $application, 'notes', 'x', $this->token($application))->assertForbidden();
        $this->assertNull($application->fresh()->notes);

        $otherProject = LeadMaster::create(['branch_id' => $application->branch_id, 'project_name' => 'Proyek Assigned Lain', 'is_active' => true]);
        $assigned = $this->userWithPermissions(['consumer_progress.manage_assigned'], $application, 'assigned-manager');
        DB::table('project_user')->insert(['project_id' => $otherProject->id, 'user_id' => $assigned->id, 'is_active' => true, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->patchCell($assigned, $application, 'notes', 'x', $this->token($application))->assertForbidden();

        $supplemental = $this->userWithPermissions(['consumer_progress.view_branch'], $application, 'supplemental-primary');
        $supplemental->roles()->attach($foreignUser->role_id);
        $this->patchCell($supplemental, $application, 'notes', 'x', $this->token($application))->assertForbidden();
        $this->assertNotNull($foreignApplication);
    }

    public function test_allowlist_invalid_stale_missing_and_unknown_contracts(): void
    {
        [$user, $application, $customer] = $this->context();
        foreach (['arbitrary', 'current_stage'] as $column) {
            $this->patchCell($user, $application, $column, 'x', $this->token($application))->assertUnprocessable()->assertJsonStructure(['errors' => ['column']]);
        }
        foreach (['bi-checking', 'psjb', 'pemberkasan', 'proses-bank', 'ppjb', 'akad', 'bast'] as $module) {
            foreach (app(DatabaseModuleRegistry::class)->get($module)['columns'] as $column) {
                $this->patchCell($user, $application, $column['key'], 'x', $this->token($application), $module)->assertUnprocessable();
            }
        }
        foreach ([['customer_name', '   '], ['phone', str_repeat('1', 51)], ['notes', str_repeat('x', 5001)], ['status_cash', 'maybe']] as [$column, $value]) {
            $target = in_array($column, ['customer_name', 'phone'], true) ? $customer : $application;
            $response = $this->patchCell($user, $application, $column, $value, $this->token($target))->assertUnprocessable();
            $this->assertArrayHasKey('value', $response->json('errors'));
        }
        $this->actingAs($user)->patchJson(route('consumer-database.cell.update', ['data-konsumen', 999999]), ['column' => 'notes', 'value' => 'x', 'expected_updated_at' => now()->toISOString()])->assertNotFound();
        $unknown = str_replace('/data-konsumen/', '/unknown/', route('consumer-database.cell.update', ['data-konsumen', $application->id]));
        $this->actingAs($user)->patchJson($unknown, ['column' => 'notes', 'value' => 'x', 'expected_updated_at' => $this->token($application)])->assertNotFound();
    }

    public function test_invalid_expected_timestamp_is_422(): void
    {
        [$user, $application] = $this->context();
        $this->actingAs($user)->patchJson(route('consumer-database.cell.update', ['data-konsumen', $application->id]), ['column' => 'notes', 'value' => 'x', 'expected_updated_at' => 'invalid'])->assertUnprocessable();
    }

    public function test_stale_application_token_returns_409_without_overwrite(): void
    {
        [$user, $application] = $this->context();
        $application->update(['notes' => 'lebih baru']);
        $this->patchCell($user, $application, 'notes', 'tertindih', now()->subDay()->toISOString())->assertConflict()->assertJsonPath('current_value', 'lebih baru');
        $this->assertSame('lebih baru', $application->fresh()->notes);
    }

    public function test_table_and_sheet_share_named_endpoint_and_editor_semantics(): void
    {
        [$user, $application] = $this->context();
        foreach (['table', 'sheet'] as $view) {
            $response = $this->actingAs($user)->get(route('consumer-database.module', ['module' => 'data-konsumen', 'view' => $view]))->assertOk()
                ->assertSee('consumerDatabaseCellEditor', false)->assertSee('Edit Nama Konsumen')->assertDontSee('contenteditable', false);
            $this->assertSame(route('consumer-database.cell.update', ['data-konsumen', $application->id]), $response->viewData('rows')->first()['update_url']);
        }
    }

    private function patchCell(User $user, ConsumerApplication $application, string $column, mixed $value, string $token, string $module = 'data-konsumen')
    {
        return $this->actingAs($user)->patchJson(route('consumer-database.cell.update', [$module, $application->id]), ['column' => $column, 'value' => $value, 'expected_updated_at' => $token]);
    }

    private function context(): array
    {
        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin', 'is_superadmin' => true]);
        $role->update(['is_superadmin' => true]);
        $branch = Branch::create(['name' => 'Cabang Utama', 'code' => 'CU'.str()->random(4), 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Utama', 'is_active' => true]);
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $customer = Customer::create(['name' => 'Nama Lama', 'phone' => '081111111111']);
        $application = ConsumerApplication::create(['customer_id' => $customer->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'application_status' => 'active']);

        return [$user, $application, $customer];
    }

    private function scopedContext(string $permission): array
    {
        [, $application] = $this->context();

        return [$this->userWithPermissions([$permission], $application, 'scoped-'.str()->random(4)), $application];
    }

    private function userWithPermissions(array $slugs, ConsumerApplication $application, string $roleSlug): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug, 'is_superadmin' => false]);
        $permissionIds = [];
        foreach ($slugs as $slug) {
            $permissionIds[] = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'group_name' => 'Test'])->id;
        }
        $role->permissions()->sync($permissionIds);
        Permission::resetRegisteredSlugs();
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $application->branch_id, 'password_changed_at' => now()]);
        DB::table('branch_user')->updateOrInsert(['branch_id' => $application->branch_id, 'user_id' => $user->id], ['can_view' => true, 'can_edit' => true, 'can_sync' => false, 'can_manage_members' => false, 'created_at' => now(), 'updated_at' => now()]);

        return $user->fresh('role.permissions');
    }

    private function token(object $model): string
    {
        return app(ConsumerDatabaseWriteService::class)->token($model);
    }
}
