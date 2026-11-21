<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrdV3RolePermissionParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_br_fresh_and_legacy_database_role_permission_profiles_match(): void
    {
        $slugs = ['admin', 'sas', 'sam', 'staff'];
        $fresh = $this->rolePermissionSets($slugs);

        DB::table('role_permission')->whereIn('role_id', Role::query()->whereIn('slug', $slugs)->pluck('id'))->delete();
        $migration = require base_path('database/migrations/2026_11_20_210003_complete_prd_v3_legacy_role_profiles.php');
        $migration->up();
        $permissionMigration = require base_path('database/migrations/2026_11_21_230001_add_consumer_migration_reconcile_permission.php');
        $permissionMigration->up();

        $this->assertSame($fresh, $this->rolePermissionSets($slugs));
    }

    public function test_br_d32_sas_delta_adds_only_consumer_progress_manage(): void
    {
        $snapshot = json_decode(file_get_contents(base_path('tests/Fixtures/permission_snapshot_pre_v3.json')), true, 512, JSON_THROW_ON_ERROR);
        $sas = Role::query()->where('slug', 'sas')->firstOrFail();
        $actual = $sas->permissions()->pluck('slug')->sort()->values()->all();
        $expected = collect($snapshot['effective_permissions_by_primary_role']['sas'])
            ->push('consumer_progress.manage')
            ->push('consumer_migration.reconcile')
            ->sort()->values()->all();

        $this->assertSame($expected, $actual);
        $this->assertSame(
            $snapshot['effective_permissions_by_primary_role']['sam'],
            Role::query()->where('slug', 'sam')->firstOrFail()->permissions()->pluck('slug')->sort()->values()->all(),
        );
        $this->assertSame(
            $snapshot['effective_permissions_by_primary_role']['staff'],
            Role::query()->where('slug', 'staff')->firstOrFail()->permissions()->pluck('slug')->sort()->values()->all(),
        );
    }

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
            if ($staffType === 'sales') {
                $expected[] = 'consumer_progress.manage';
                $expected[] = 'consumer_migration.reconcile';
                sort($expected);
            }
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

    public function test_br_staff_permission_checks_memoize_legacy_profile_per_user_instance(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'staff')->firstOrFail()->id,
            'staff_type' => 'admin_cabang',
        ]);
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'role_permission')) {
                $queries++;
            }
        });

        $user->hasPermission('users.view');
        $user->hasPermission('users.create');
        $user->hasPermission('users.update');

        $this->assertSame(1, $queries);
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

    /** @param array<int, string> $slugs @return array<string, array<int, string>> */
    private function rolePermissionSets(array $slugs): array
    {
        return Role::query()->whereIn('slug', $slugs)->get()->sortBy('slug')->mapWithKeys(
            fn (Role $role): array => [$role->slug => $role->permissions()->pluck('slug')->sort()->values()->all()],
        )->all();
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
