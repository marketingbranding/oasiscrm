<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrdV3RoleMigrationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_br06_d6_roles_v3_migrate_dry_run_reports_without_mutation(): void
    {
        // BR-06 / D6: transition is operator-controlled and dry-run writes nothing.
        $roles = $this->legacyRoles();
        $users = collect(['admin', 'sas', 'sam', 'staff'])->mapWithKeys(
            fn (string $slug): array => [$slug => User::factory()->create(['role_id' => $roles[$slug]->id])],
        );
        $supplemental = Role::query()->where('slug', 'manager')->firstOrFail();
        $users['admin']->roles()->attach($supplemental);
        $before = [
            'role_ids' => $users->mapWithKeys(fn (User $user, string $slug): array => [$slug => $user->role_id])->all(),
            'staff_types' => $users->mapWithKeys(fn (User $user, string $slug): array => [$slug => $user->staff_type])->all(),
            'scope_levels' => $users->mapWithKeys(fn (User $user, string $slug): array => [$slug => $user->scope_level])->all(),
            'role_active' => $roles->mapWithKeys(fn (Role $role, string $slug): array => [$slug => $role->is_active])->all(),
            'supplemental_count' => DB::table('role_user')->where('user_id', $users['admin']->id)->count(),
        ];

        $this->artisan('oasis:roles-v3-migrate', ['--dry-run' => true])
            ->expectsOutputToContain('Mode: dry-run')
            ->expectsOutputToContain('Kandidat: 4')
            ->assertSuccessful();

        $freshUsers = $users->map(fn (User $user): User => $user->fresh());
        $freshRoles = $roles->map(fn (Role $role): Role => $role->fresh());
        $this->assertSame($before['role_ids'], $freshUsers->mapWithKeys(fn (User $user, string $slug): array => [$slug => $user->role_id])->all());
        $this->assertSame($before['staff_types'], $freshUsers->mapWithKeys(fn (User $user, string $slug): array => [$slug => $user->staff_type])->all());
        $this->assertSame($before['scope_levels'], $freshUsers->mapWithKeys(fn (User $user, string $slug): array => [$slug => $user->scope_level])->all());
        $this->assertSame($before['role_active'], $freshRoles->mapWithKeys(fn (Role $role, string $slug): array => [$slug => $role->is_active])->all());
        $this->assertSame($before['supplemental_count'], DB::table('role_user')->where('user_id', $users['admin']->id)->count());
    }

    public function test_br21_d21_roles_v3_migrate_maps_legacy_users_idempotently(): void
    {
        // BR-21 / D21: preserve user IDs and supplemental memberships during role transition.
        $roles = $this->legacyRoles();
        $users = collect(['admin', 'sas', 'sam', 'staff'])->mapWithKeys(
            fn (string $slug): array => [$slug => User::factory()->create(['role_id' => $roles[$slug]->id])],
        );
        $supplemental = Role::query()->where('slug', 'manager')->firstOrFail();
        $users['admin']->roles()->attach($supplemental);
        $ids = $users->mapWithKeys(fn (User $user, string $slug): array => [$slug => $user->id])->all();

        $this->artisan('oasis:roles-v3-migrate')->assertSuccessful();

        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();
        $this->assertDatabaseHas('users', ['id' => $ids['admin'], 'role_id' => $staffRole->id, 'staff_type' => 'admin_cabang']);
        $this->assertDatabaseHas('users', ['id' => $ids['sas'], 'role_id' => $staffRole->id, 'staff_type' => 'sales']);
        $this->assertDatabaseHas('users', ['id' => $ids['sam'], 'role_id' => $staffRole->id, 'staff_type' => 'marketing']);
        $this->assertDatabaseHas('users', ['id' => $ids['staff'], 'role_id' => $staffRole->id, 'staff_type' => 'umum']);
        $this->assertDatabaseHas('role_user', ['user_id' => $ids['admin'], 'role_id' => $supplemental->id]);
        $this->assertDatabaseHas('roles', ['slug' => 'admin', 'is_active' => false]);
        $this->assertDatabaseHas('roles', ['slug' => 'sas', 'is_active' => false]);
        $this->assertDatabaseHas('roles', ['slug' => 'sam', 'is_active' => false]);
        $this->assertDatabaseHas('roles', ['slug' => 'staff', 'is_active' => true]);

        $afterFirstRun = DB::table('users')->whereIn('id', $ids)->orderBy('id')->get()->toJson();
        $this->artisan('oasis:roles-v3-migrate')
            ->expectsOutputToContain('Kandidat: 0')
            ->assertSuccessful();
        $this->assertSame($afterFirstRun, DB::table('users')->whereIn('id', $ids)->orderBy('id')->get()->toJson());
    }

    public function test_br25_d25_roles_v3_migrate_backfills_scope_level_from_branch(): void
    {
        // BR-25 / D25: scope_level follows placement, pusat without branch and cabang with branch.
        $roles = $this->legacyRoles();
        $branchId = DB::table('branches')->insertGetId([
            'name' => 'Cabang Migrasi Role',
            'code' => 'MIG-ROLE',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pusat = User::factory()->create(['role_id' => $roles['admin']->id, 'branch_id' => null]);
        $cabang = User::factory()->create(['role_id' => $roles['sas']->id, 'branch_id' => $branchId]);

        $this->artisan('oasis:roles-v3-migrate')->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $pusat->id, 'scope_level' => 'pusat']);
        $this->assertDatabaseHas('users', ['id' => $cabang->id, 'scope_level' => 'cabang']);
    }

    /**
     * @return Collection<string, Role>
     */
    private function legacyRoles(): Collection
    {
        return Role::query()->whereIn('slug', ['admin', 'sas', 'sam', 'staff'])->get()->keyBy('slug');
    }
}
