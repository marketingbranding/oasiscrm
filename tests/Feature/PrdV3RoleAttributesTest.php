<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PrdV3RoleAttributesTest extends TestCase
{
    use RefreshDatabase;

    public function test_br_role_attributes_and_ass_manager_are_available(): void
    {
        $manager = Role::query()->where('slug', 'manager')->firstOrFail();
        $assManager = Role::query()->where('slug', 'ass_manager')->firstOrFail();

        $this->assertTrue(Schema::hasColumn('users', 'sales_type'));
        $this->assertTrue(Schema::hasColumn('users', 'staff_type'));
        $this->assertTrue(Schema::hasColumn('users', 'scope_level'));
        $this->assertSame(
            ['sales_type', 'staff_type', 'scope_level'],
            collect((new User)->getFillable())->intersect(['sales_type', 'staff_type', 'scope_level'])->values()->all(),
        );
        $this->assertSame('string', (new User)->getCasts()['sales_type']);
        $this->assertSame('string', (new User)->getCasts()['staff_type']);
        $this->assertSame('string', (new User)->getCasts()['scope_level']);
        $this->assertSame('Ass. Manager', $assManager->name);
        $this->assertSame($manager->authority_level, $assManager->authority_level);
        $this->assertFalse($assManager->is_superadmin);
        $this->assertSame(
            $manager->permissions()->pluck('slug')->sort()->values()->all(),
            $assManager->permissions()->pluck('slug')->sort()->values()->all(),
        );
        $this->assertDatabaseHas('changelogs', [
            'version' => null,
            'title' => 'Tambahkan atribut user PRD v3 dan role Ass. Manager',
        ]);
    }

    public function test_br_role_attributes_backfill_legacy_roles_without_cutover(): void
    {
        $roles = collect(['admin', 'sas', 'sam', 'staff'])
            ->mapWithKeys(fn (string $slug): array => [$slug => Role::query()->where('slug', $slug)->value('id')]);
        $branchId = DB::table('branches')->insertGetId([
            'name' => 'Cabang Uji',
            'code' => 'UJI-ATTR',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $preservedUser = User::factory()->create([
            'role_id' => $roles['admin'],
            'sales_type' => 'personal',
            'staff_type' => 'existing',
            'scope_level' => 'khusus',
        ]);
        $userIds = [];

        foreach ($roles as $slug => $roleId) {
            $userIds[$slug] = User::factory()->create([
                'role_id' => $roleId,
                'branch_id' => $slug === 'admin' ? $branchId : null,
                'sales_type' => null,
                'staff_type' => null,
                'scope_level' => null,
            ])->id;
        }

        $migration = require database_path('migrations/'.collect(glob(database_path('migrations/*add_prd_v3_user_attributes_and_ass_manager.php')))->map('basename')->first());
        $migration->up();

        $this->assertDatabaseHas('users', ['id' => $userIds['admin'], 'staff_type' => 'admin_cabang', 'scope_level' => 'cabang']);
        $this->assertDatabaseHas('users', ['id' => $preservedUser->id, 'sales_type' => 'personal', 'staff_type' => 'existing', 'scope_level' => 'khusus']);
        $this->assertDatabaseHas('users', ['id' => $userIds['sas'], 'staff_type' => 'sales', 'scope_level' => 'pusat']);
        $this->assertDatabaseHas('users', ['id' => $userIds['sam'], 'staff_type' => 'marketing', 'scope_level' => 'pusat']);
        $this->assertDatabaseHas('users', ['id' => $userIds['staff'], 'staff_type' => 'umum', 'scope_level' => 'pusat']);

        foreach ($roles as $roleId) {
            $this->assertDatabaseHas('roles', ['id' => $roleId]);
        }
        $this->assertSame(
            collect($userIds)->mapWithKeys(fn (int $userId, string $slug): array => [$userId => $roles[$slug]])->all(),
            DB::table('users')->whereIn('id', $userIds)->pluck('role_id', 'id')->all(),
        );
    }
}
