<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrdV3RolePermissionParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_br_staff_type_permissions_match_pre_v3_snapshot(): void
    {
        $snapshot = json_decode(file_get_contents(base_path('tests/Fixtures/permission_snapshot_pre_v3.json')), true, 512, JSON_THROW_ON_ERROR);
        $staffTypeRoles = [
            'admin_cabang' => 'admin',
            'sales' => 'sas',
            'marketing' => 'sam',
            'umum' => 'staff',
        ];
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();

        foreach ($staffTypeRoles as $staffType => $legacyRole) {
            $user = User::factory()->create([
                'role_id' => $staffRole->id,
                'staff_type' => $staffType,
            ]);
            $expected = $snapshot['effective_permissions_by_primary_role'][$legacyRole];
            $actual = collect(PermissionCatalog::permissions())
                ->pluck('slug')
                ->filter(fn (string $permission): bool => $user->hasPermission($permission))
                ->sort()
                ->values()
                ->all();

            $this->assertSame($expected, $actual, $staffType);
        }
    }

    public function test_br_staff_without_staff_type_gets_no_staff_admin_permissions(): void
    {
        $staff = Role::query()->where('slug', 'staff')->firstOrFail();
        $user = User::factory()->create(['role_id' => $staff->id]);

        $this->assertFalse($user->hasPermission('users.view'));
        $this->assertFalse($user->hasPermission('sales_pocketbook.view_branch'));
    }

    public function test_br_supplemental_role_does_not_elevate_staff_permissions(): void
    {
        $staff = Role::query()->where('slug', 'staff')->firstOrFail();
        $admin = Role::query()->where('slug', 'admin')->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $staff->id,
            'staff_type' => 'umum',
        ]);
        $user->roles()->attach($admin);

        $this->assertFalse($user->hasPermission('users.view'));
        $this->assertFalse($user->hasPermission('system.maintenance_bypass'));
    }

    public function test_staff_type_permission_catalog_has_exact_canonical_keys(): void
    {
        $this->assertSame(
            ['admin_cabang', 'sales', 'marketing', 'umum'],
            array_keys(PermissionCatalog::staffTypePermissions()),
        );
    }
}
