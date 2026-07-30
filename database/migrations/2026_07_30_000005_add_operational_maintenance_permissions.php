<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUGS = [
        'system.maintenance_bypass',
        'system.maintenance_manage',
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

        $pusatRoleId = DB::table('roles')->where('slug', 'pusat')->value('id');
        $bypassPermissionId = DB::table('permissions')->where('slug', 'system.maintenance_bypass')->value('id');

        if ($pusatRoleId && $bypassPermissionId) {
            DB::table('role_permission')->insertOrIgnore([
                'permission_id' => $bypassPermissionId,
                'role_id' => $pusatRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Additive permissions and custom role grants are intentionally preserved.
    }
};
