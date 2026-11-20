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

    public function test_br_staff_without_staff_type_keeps_legacy_primary_role_permissions_during_transition(): void
    {
        $staff = Role::query()->where('slug', 'staff')->firstOrFail();
        $user = User::factory()->create(['role_id' => $staff->id, 'staff_type' => null]);

        $expected = $staff->permissions()->pluck('slug')->sort()->values()->all();
        $actual = collect(PermissionCatalog::permissions())
            ->pluck('slug')
            ->filter(fn (string $permission): bool => $user->hasPermission($permission))
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $actual);
    }

    public function test_br_staff_with_unknown_staff_type_cannot_elevate_permissions(): void
    {
        $staff = Role::query()->where('slug', 'staff')->firstOrFail();
        $user = User::factory()->create(['role_id' => $staff->id, 'staff_type' => 'unknown']);

        $this->assertFalse($user->hasPermission('users.view'));
        $this->assertFalse($user->hasPermission('bridge_fund.manage'));
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

    public function test_br_staff_type_permissions_read_legacy_role_database_profile(): void
    {
        $admin = Role::query()->where('slug', 'admin')->firstOrFail();
        $admin->permissions()->syncWithoutDetaching([
            $admin->permissions()->where('permissions.slug', 'users.view')->pluck('permissions.id')->first(),
        ]);
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'staff')->firstOrFail()->id,
            'staff_type' => 'admin_cabang',
        ]);

        $this->assertTrue($user->hasPermission('users.view'));
    }

    public function test_br_admin_cabang_helper_covers_legacy_and_staff_profiles_only(): void
    {
        $admin = Role::query()->where('slug', 'admin')->firstOrFail();
        $staff = Role::query()->where('slug', 'staff')->firstOrFail();

        $this->assertTrue(User::factory()->create(['role_id' => $admin->id])->isAdminCabang());
        $this->assertTrue(User::factory()->create(['role_id' => $staff->id, 'staff_type' => 'admin_cabang'])->isAdminCabang());
        $this->assertFalse(User::factory()->create(['role_id' => $staff->id, 'staff_type' => 'sales'])->isAdminCabang());
    }
}
