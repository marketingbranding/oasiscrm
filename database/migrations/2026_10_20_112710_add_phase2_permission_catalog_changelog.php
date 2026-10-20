<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $slugs = [
            'sales_targets.view_own', 'sales_targets.view_team', 'sales_targets.view_branch', 'sales_targets.view_all', 'sales_targets.manage_all',
            'sales_daily_plans.view_own', 'sales_daily_plans.view_team', 'sales_daily_plans.view_branch', 'sales_daily_plans.view_all',
            'sales_daily_plans.manage_own', 'sales_daily_plans.manage_team', 'sales_daily_plans.submit_own',
        ];
        $permissions = collect(PermissionCatalog::permissions())->whereIn('slug', $slugs);
        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(['slug' => $permission['slug']], [
                ...$permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id', 'slug');
        foreach (PermissionCatalog::rolePermissions() as $role => $rolePermissions) {
            $roleId = DB::table('roles')->where('slug', $role)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach (array_intersect($rolePermissions, $slugs) as $slug) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionIds[$slug],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        $all = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        $superadmin = DB::table('roles')->where('slug', 'superadmin')->value('id');
        if ($superadmin) {
            foreach ($all as $permissionId) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $superadmin,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Perencanaan Harian Buku Saku Sales'],
            ['category' => 'added', 'description' => 'Menambahkan target lead bulanan, Daily Plan 420 menit, penjadwalan dengan jeda makan siang, kewenangan Coordinator, dan pengingat Agenda Buku Saku Sales.', 'created_by' => null, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $slugs = [
            'sales_targets.view_own', 'sales_targets.view_team', 'sales_targets.view_branch', 'sales_targets.view_all', 'sales_targets.manage_all',
            'sales_daily_plans.view_own', 'sales_daily_plans.view_team', 'sales_daily_plans.view_branch', 'sales_daily_plans.view_all',
            'sales_daily_plans.manage_own', 'sales_daily_plans.manage_team', 'sales_daily_plans.submit_own',
        ];
        $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        DB::table('changelogs')->whereNull('version')->where('title', 'Perencanaan Harian Buku Saku Sales')->delete();
    }
};
