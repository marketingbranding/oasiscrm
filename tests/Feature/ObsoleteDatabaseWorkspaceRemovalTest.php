<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ObsoleteDatabaseWorkspaceRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_obsolete_routes_are_unregistered_and_urls_return_not_found(): void
    {
        $names = [
            'consumer-local.index', 'consumer-local.create', 'consumer-local.show', 'consumer-local.store', 'consumer-local.edit', 'consumer-local.update',
            'consumer-local.bi-checking.create', 'consumer-local.bi-checking.store', 'consumer-local.psjb.create', 'consumer-local.psjb.store',
            'consumer-local.bank.create', 'consumer-local.bank.store', 'consumer-local.ppjb.create', 'consumer-local.ppjb.store',
            'consumer-local.akad.create', 'consumer-local.akad.store', 'consumer-local.bast.create', 'consumer-local.bast.store', 'consumer-local.nik-reveal',
            'consumer-database.index', 'consumer-database.module', 'consumer-database.cell.update',
            'database-v2.index', 'database-v2.list', 'database-v2.export', 'database-v2.store', 'database-v2.update', 'database-v2.destroy',
            'database-v2.import.preview', 'database-v2.import.save',
        ];
        foreach ($names as $name) {
            $this->assertNull(Route::getRoutes()->getByName($name), $name);
        }

        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);
        foreach (['/konsumen-progress-local', '/consumer-database', '/consumer-database/data-konsumen', '/database-v2', '/database-v2/data_konsumen/list'] as $url) {
            $this->actingAs($user)->get($url)->assertNotFound();
        }
        $this->actingAs($user)->post('/konsumen-progress-local', [])->assertNotFound();
        $this->actingAs($user)->patch('/consumer-database/data-konsumen/1/cell', [])->assertNotFound();
        $this->actingAs($user)->post('/database-v2/data_konsumen', [])->assertNotFound();
        $this->actingAs($user)->put('/database-v2/data_konsumen/1', [])->assertNotFound();
        $this->actingAs($user)->delete('/database-v2/data_konsumen/1')->assertNotFound();
    }

    public function test_database_and_konsumen_progress_remain_registered(): void
    {
        foreach (['database.index', 'database.sync', 'konsumen-progress.index', 'konsumen-progress.stage', 'konsumen-progress.sync'] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name), $name);
        }
    }

    public function test_database_v2_permissions_are_removed_but_tables_and_data_remain(): void
    {
        $this->assertSame([], collect(PermissionCatalog::permissions())->pluck('slug')->filter(fn (string $slug): bool => str_starts_with($slug, 'database_v2.'))->values()->all());
        $this->assertSame([], collect(PermissionCatalog::rolePermissions())->flatten()->filter(fn (string $slug): bool => str_starts_with($slug, 'database_v2.'))->values()->all());
        $this->assertSame(0, DB::table('permissions')->where('slug', 'like', 'database_v2.%')->count());

        $tables = ['db_v2_data_konsumen', 'db_v2_bi_checking', 'db_v2_psjb', 'db_v2_pemberkasan', 'db_v2_proses_bank', 'db_v2_ppjb_dev', 'db_v2_akad', 'db_v2_bast'];
        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $branchId = DB::table('branches')->insertGetId([
            'name' => 'Cabang Data V2',
            'code' => 'DV2',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('db_v2_data_konsumen')->insert([
            'branch_id' => $branchId,
            'nama_konsumen' => 'Data V2 Dipertahankan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_08_24_000009_remove_obsolete_database_workspaces.php');
        $migration->up();
        $migration->up();

        $this->assertDatabaseHas('db_v2_data_konsumen', ['nama_konsumen' => 'Data V2 Dipertahankan']);
        $this->assertSame(0, DB::table('permissions')->where('slug', 'like', 'database_v2.%')->count());
    }

    public function test_permission_removal_is_exact_and_rollback_restores_custom_role_grants(): void
    {
        $migration = require database_path('migrations/2026_08_24_000009_remove_obsolete_database_workspaces.php');
        $migration->down();
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Custom V2',
            'slug' => 'custom-v2',
            'is_active' => true,
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('permissions')->updateOrInsert(
            ['slug' => 'database_v2.view'],
            [
                'name' => 'Melihat Database V2',
                'description' => 'Melihat data Database V2 sesuai lingkup akses.',
                'group_name' => 'Database V2',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        $permissionId = DB::table('permissions')->where('slug', 'database_v2.view')->value('id');
        DB::table('role_permission')->insert([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('permissions')->insert([
            'name' => 'Permission Mirip',
            'slug' => 'databaseXv2.view',
            'description' => 'Tidak boleh terhapus.',
            'group_name' => 'Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();
        $this->assertDatabaseHas('permissions', ['slug' => 'databaseXv2.view']);
        $this->assertDatabaseMissing('permissions', ['slug' => 'database_v2.view']);
        $this->assertDatabaseHas('database_v2_role_permission_archives', ['role_id' => $roleId, 'permission_slug' => 'database_v2.view']);

        $migration->down();
        $restoredPermissionId = DB::table('permissions')->where('slug', 'database_v2.view')->value('id');
        $this->assertDatabaseHas('role_permission', ['role_id' => $roleId, 'permission_id' => $restoredPermissionId]);

        $migration->up();
    }

    public function test_removal_changelog_is_unique_and_visible(): void
    {
        $title = 'Workspace Database Konsumen Lama Dihapus';
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->actingAs($user)->get(route('changelogs.index'))->assertOk()->assertSeeText($title);
    }
}
