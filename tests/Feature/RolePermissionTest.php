<?php

namespace Tests\Feature;

use App\Models\Changelog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'active', 'permission:users.view'])
            ->get('/_test/permission', fn () => response('allowed'));
    }

    public function test_canonical_and_legacy_roles_are_inserted_with_expected_labels(): void
    {
        $expected = [
            'sales' => 'Sales',
            'sales_coordinator' => 'Koordinator Sales',
            'supervisor' => 'Supervisor',
            'manager' => 'Manager',
            'branch_manager' => 'Branch Manager',
            'pusat' => 'Tim Pusat',
            'superadmin' => 'Super Admin',
            'admin' => 'Admin',
            'staff' => 'Staff',
        ];

        foreach ($expected as $slug => $name) {
            $this->assertDatabaseHas('roles', ['slug' => $slug, 'name' => $name, 'is_active' => true]);
        }

        $this->assertSame(1, Role::query()->where('is_superadmin', true)->count());
        $this->assertTrue(Role::query()->where('slug', 'superadmin')->value('is_superadmin'));
    }

    public function test_default_mapping_uses_scoped_permissions(): void
    {
        $sales = Role::query()->where('slug', 'sales')->firstOrFail();
        $coordinator = Role::query()->where('slug', 'sales_coordinator')->firstOrFail();
        $manager = Role::query()->where('slug', 'manager')->firstOrFail();

        $this->assertTrue($sales->permissions()->where('slug', 'sales_pocketbook.view_own')->exists());
        $this->assertTrue($sales->permissions()->where('slug', 'work_planner.create')->exists());
        $this->assertFalse($sales->permissions()->where('slug', 'sales_pocketbook.view_team')->exists());
        $this->assertTrue($coordinator->permissions()->where('slug', 'sales_pocketbook.view_team')->exists());
        $this->assertTrue($manager->permissions()->where('slug', 'database.export_assigned')->exists());
        $this->assertFalse($manager->permissions()->where('slug', 'database.manage_assigned')->exists());
    }

    public function test_superadmin_has_registered_permissions_without_pivot_mapping(): void
    {
        $role = Role::query()->where('slug', 'superadmin')->firstOrFail();
        $role->permissions()->detach();
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->assertTrue($user->hasPermission('roles.manage'));
        $this->assertTrue(Gate::forUser($user)->allows('permissions.manage'));
        $this->assertFalse($user->hasPermission('permission.that-does-not-exist'));
    }

    public function test_pusat_does_not_receive_sensitive_permissions(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'pusat')->value('id'),
        ]);

        foreach (['roles.manage', 'permissions.manage', 'branches.manage', 'projects.manage', 'system_health.view', 'users.delete_permanently', 'expenses.configure'] as $slug) {
            $this->assertFalse($user->hasPermission($slug), $slug);
        }
        $this->assertTrue($user->hasAllPermissions(['users.invite', 'expenses.view', 'expenses.view_all', 'activity_logs.view']));
        $this->assertTrue($user->hasPermission('system.maintenance_bypass'));
        $this->assertFalse($user->hasPermission('system.maintenance_manage'));
    }

    public function test_permission_middleware_allows_and_denies_by_primary_role(): void
    {
        $allowed = User::factory()->create(['role_id' => Role::query()->where('slug', 'pusat')->value('id')]);
        $denied = User::factory()->create(['role_id' => Role::query()->where('slug', 'sales')->value('id')]);

        $this->actingAs($allowed)->get('/_test/permission')->assertOk()->assertSee('allowed');
        $this->actingAs($denied)->get('/_test/permission')->assertForbidden();
    }

    public function test_supplemental_privileged_role_does_not_grant_primary_role_permission(): void
    {
        $sales = Role::query()->where('slug', 'sales')->firstOrFail();
        $pusat = Role::query()->where('slug', 'pusat')->firstOrFail();
        $user = User::factory()->create(['role_id' => $sales->id]);
        $user->roles()->attach($pusat);

        $this->assertTrue($user->hasRole('pusat'));
        $this->assertFalse($user->hasPermission('users.view'));
        $this->assertFalse(Gate::forUser($user)->allows('users.view'));
        $this->assertFalse($user->hasPermission('system.maintenance_bypass'));
    }

    public function test_permission_changelog_is_deployed_once_and_rendered(): void
    {
        $title = 'Peran Organisasi dan Izin Akses';
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'pusat')->value('id'),
            'password_changed_at' => now(),
        ]);

        $this->assertSame(1, Changelog::query()->whereNull('version')->where('title', $title)->count());
        $this->actingAs($user)->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }
}
