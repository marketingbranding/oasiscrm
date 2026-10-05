<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermissionDriftSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_lifecycle_privacy_permissions_are_registered(): void
    {
        foreach (['users.anonymize', 'users.release_email'] as $slug) {
            $this->assertDatabaseHas('permissions', ['slug' => $slug]);
        }
    }

    public function test_retired_organization_permissions_are_not_registered_or_mapped(): void
    {
        $retired = ['organization.manage', 'organization.configure_rules'];

        $this->assertEmpty(array_intersect($retired, collect(PermissionCatalog::permissions())->pluck('slug')->all()));
        $this->assertSame([], DB::table('permissions')->whereIn('slug', $retired)->pluck('slug')->all());
        $this->assertSame([], DB::table('role_permission')->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')->whereIn('permissions.slug', $retired)->pluck('permissions.slug')->all());
    }

    public function test_permission_retirement_has_one_changelog_entry(): void
    {
        $this->assertSame(1, DB::table('changelogs')
            ->whereNull('version')
            ->where('title', 'Permission Organisasi Legacy Dihentikan')
            ->count());
    }

    public function test_anonymize_and_release_email_mapped_to_superadmin_and_pusat_only(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('slug', ['users.anonymize', 'users.release_email'])->pluck('id');

        foreach (['superadmin', 'pusat'] as $slug) {
            $roleId = Role::where('slug', $slug)->value('id');
            $this->assertSame($permissionIds->count(), DB::table('role_permission')->where('role_id', $roleId)->whereIn('permission_id', $permissionIds)->count(), $slug);
        }

        foreach (['branch_manager', 'admin', 'manager', 'supervisor', 'staff', 'sales', 'sales_coordinator'] as $slug) {
            $roleId = Role::where('slug', $slug)->value('id');
            $this->assertSame(0, DB::table('role_permission')->where('role_id', $roleId)->whereIn('permission_id', $permissionIds)->count(), $slug);
        }
    }

    public function test_pusat_still_lacks_delete_permanently(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'pusat')->value('id'),
        ]);

        $this->assertFalse($user->hasPermission('users.delete_permanently'));
    }

    public function test_catalog_mapping_is_fully_synced_to_deployed_pivots(): void
    {
        $permissionIds = DB::table('permissions')->pluck('id', 'slug');

        foreach (PermissionCatalog::rolePermissions() as $roleSlug => $permissionSlugs) {
            $roleId = Role::where('slug', $roleSlug)->value('id');
            $this->assertNotNull($roleId, "Role {$roleSlug} missing");

            foreach ($permissionSlugs as $slug) {
                $permissionId = $permissionIds[$slug] ?? null;
                if ($permissionId === null) {
                    continue;
                }

                $this->assertDatabaseHas('role_permission', [
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function test_supplemental_role_never_grants_permissions(): void
    {
        $pusat = Role::where('slug', 'pusat')->value('id');
        $sales = Role::where('slug', 'sales')->value('id');
        $user = User::factory()->create(['role_id' => $pusat]);
        $user->roles()->attach($sales);

        $this->assertFalse($user->hasPermission('sales_pocketbook.view_own'));
        $this->assertTrue($user->hasRole('sales'));
        $this->assertFalse($user->isSales());
    }
}
