<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserImportBatch;
use App\Models\UserImportRow;
use App\Policies\UserImportBatchPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesUserImportWorkbooks;
use Tests\TestCase;

class AdminUserImportAuthorizationSecurityTest extends TestCase
{
    use CreatesUserImportWorkbooks;
    use RefreshDatabase;

    public function test_all_seven_bulk_routes_require_all_six_permissions(): void
    {
        $middleware = 'permissions.all:'.implode(',', UserImportBatchPolicy::REQUIRED_PERMISSIONS);
        $names = [
            'admin-users.import', 'admin-users.import-preview', 'admin-users.import-confirm',
            'admin-users.import-template', 'admin-users.import-history', 'admin-users.import-result',
            'admin-users.import-batches.show',
        ];

        foreach ($names as $name) {
            $this->assertContains($middleware, Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    public function test_superadmin_and_fully_permitted_pusat_have_direct_access_but_each_missing_permission_denies_pusat(): void
    {
        $this->actingAs($this->importActor())->get(route('admin-users.import'))->assertOk();
        $pusatRole = Role::where('slug', 'pusat')->firstOrFail();
        $permissionIds = Permission::whereIn('slug', UserImportBatchPolicy::REQUIRED_PERMISSIONS)->pluck('id');
        $pusatRole->permissions()->sync($permissionIds);
        $pusat = $this->importActor('pusat');
        $this->actingAs($pusat)->get(route('admin-users.import'))->assertOk();

        foreach (UserImportBatchPolicy::REQUIRED_PERMISSIONS as $missing) {
            $pusatRole->permissions()->sync(Permission::whereIn('slug', array_diff(UserImportBatchPolicy::REQUIRED_PERMISSIONS, [$missing]))->pluck('id'));
            $this->actingAs($pusat->fresh())->get(route('admin-users.import'))->assertForbidden();
        }
    }

    public function test_sales_manager_guest_and_cross_owner_cannot_access_batches_while_superadmin_can(): void
    {
        $ownerRole = Role::where('slug', 'pusat')->firstOrFail();
        $ownerRole->permissions()->sync(Permission::whereIn('slug', UserImportBatchPolicy::REQUIRED_PERMISSIONS)->pluck('id'));
        $owner = $this->importActor('pusat');
        $other = $this->importActor('pusat');
        $batch = UserImportBatch::create([
            'original_filename' => 'private.xlsx', 'uploaded_by' => $owner->id,
            'status' => UserImportBatch::STATUS_PREVIEW_READY, 'expires_at' => now()->addHour(),
        ]);

        $this->get(route('admin-users.import'))->assertRedirect(route('login'));
        foreach (['sales', 'manager'] as $role) {
            $this->actingAs($this->importActor($role))->get(route('admin-users.import'))->assertForbidden();
        }
        $this->actingAs($other)->get(route('admin-users.import-batches.show', $batch))->assertForbidden();
        $this->actingAs($other)->get(route('admin-users.import-result', $batch))->assertForbidden();
        $this->actingAs($other)->post(route('admin-users.import-confirm'), [
            'batch_id' => $batch->id, 'expected_updated_at' => $batch->updated_at->toISOString(),
        ])->assertForbidden();
        $this->actingAs($this->importActor())->get(route('admin-users.import-batches.show', $batch))->assertOk();
    }

    public function test_confirm_ignores_tampered_preview_fields_and_uses_persisted_raw_workbook_values(): void
    {
        $actor = $this->importActor();
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $this->uploadImport($actor, [['Asli', 'asli@example.test', 'manager', 'Solo', '', '', '', '', '']]);
        $batch = UserImportBatch::firstOrFail();
        $row = $batch->rows()->firstOrFail();
        $tampered = $row->normalized_data;
        $tampered['name'] = 'Nama Browser Palsu';
        $tampered['email'] = 'tampered@example.test';
        $tampered['role_id'] = Role::where('slug', 'superadmin')->value('id');
        $tampered['primary_branch_id'] = 999999;
        $row->update(['normalized_data' => $tampered]);
        $batch->touch();

        $this->actingAs($actor)->post(route('admin-users.import-confirm'), [
            'batch_id' => $batch->id, 'expected_updated_at' => $batch->fresh()->updated_at->toISOString(),
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'asli@example.test')->firstOrFail();
        $this->assertSame('Asli', $user->name);
        $this->assertSame($branch->id, $user->branch_id);
        $this->assertSame('manager', $user->role->slug);
        $this->assertDatabaseMissing('users', ['email' => 'tampered@example.test']);
    }

    public function test_expired_changed_and_already_confirmed_batches_cannot_create_duplicate_accounts(): void
    {
        $actor = $this->importActor();
        Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $this->uploadImport($actor, [['Expired', 'expired@example.test', 'manager', 'Solo', '', '', '', '', '']]);
        $expired = UserImportBatch::firstOrFail();
        $expired->update(['expires_at' => now()->subSecond()]);
        $this->confirm($actor, $expired, $expired->updated_at->toISOString())->assertSessionHasErrors('batch_id');

        $this->uploadImport($actor, [['Changed', 'changed@example.test', 'manager', 'Solo', '', '', '', '', '']]);
        $changed = UserImportBatch::latest('id')->firstOrFail();
        $staleTimestamp = $changed->updated_at->toISOString();
        $this->travel(1)->second();
        $changed->update(['original_filename' => 'changed.xlsx']);
        $this->confirm($actor, $changed, $staleTimestamp)->assertSessionHasErrors('batch_id');

        $this->uploadImport($actor, [['Once', 'once@example.test', 'manager', 'Solo', '', '', '', '', '']]);
        $once = UserImportBatch::latest('id')->firstOrFail();
        $this->confirm($actor, $once, $once->updated_at->toISOString())->assertSessionHasNoErrors();
        $this->confirm($actor, $once->fresh(), $once->fresh()->updated_at->toISOString())->assertSessionHasErrors('batch_id');

        $this->assertDatabaseMissing('users', ['email' => 'expired@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'changed@example.test']);
        $this->assertSame(1, User::where('email', 'once@example.test')->count());
    }

    public function test_formula_inserted_into_staging_after_preview_is_critically_revalidated(): void
    {
        $actor = $this->importActor();
        Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $this->uploadImport($actor, [['Safe', 'safe@example.test', 'manager', 'Solo', '', '', '', '', '']]);
        $batch = UserImportBatch::firstOrFail();
        $row = $batch->rows()->firstOrFail();
        $raw = $row->raw_data;
        $raw['name'] = '=HYPERLINK("https://example.test")';
        $row->update(['raw_data' => $raw]);
        $batch->touch();

        $this->confirm($actor, $batch->fresh(), $batch->fresh()->updated_at->toISOString())->assertSessionHasErrors('batch_id');
        $this->assertDatabaseMissing('users', ['email' => 'safe@example.test']);
        $this->assertSame(UserImportRow::VALIDATION_ERROR, $row->fresh()->validation_status);
    }

    private function confirm(User $actor, UserImportBatch $batch, string $timestamp)
    {
        return $this->actingAs($actor)->post(route('admin-users.import-confirm'), [
            'batch_id' => $batch->id, 'expected_updated_at' => $timestamp, 'send_invitations' => '0',
        ]);
    }
}
