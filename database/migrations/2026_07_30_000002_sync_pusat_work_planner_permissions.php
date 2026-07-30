<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUGS = [
        'work_planner.create',
        'work_planner.update',
        'work_planner.assign',
        'work_planner.export',
        'work_planner.view_all',
        'work_planner.manage_all',
    ];

    public function up(): void
    {
        foreach (collect(PermissionCatalog::permissions())->whereIn('slug', self::SLUGS) as $permission) {
            DB::table('permissions')->updateOrInsert(['slug' => $permission['slug']], [
                ...$permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleId = DB::table('roles')->where('slug', 'pusat')->value('id');
        if (! $roleId) {
            return;
        }

        foreach (DB::table('permissions')->whereIn('slug', self::SLUGS)->pluck('id') as $permissionId) {
            DB::table('role_permission')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Corrective default mappings are intentionally preserved on rollback.
    }
};
