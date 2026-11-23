<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permission = collect(PermissionCatalog::permissions())->firstWhere('slug', 'system.branch_migration_manage');
        if ($permission === null) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            ['slug' => $permission['slug']],
            [...$permission, 'updated_at' => now(), 'created_at' => now()],
        );
        $permissionId = DB::table('permissions')->where('slug', $permission['slug'])->value('id');

        $superadminId = DB::table('roles')->where('slug', 'superadmin')->value('id');
        if ($superadminId !== null) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $superadminId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'system.branch_migration_manage')->value('id');
        if ($permissionId !== null) {
            DB::table('role_permission')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->whereKey($permissionId)->delete();
        }
    }
};
