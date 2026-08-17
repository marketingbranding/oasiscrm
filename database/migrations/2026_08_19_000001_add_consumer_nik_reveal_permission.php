<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUG = 'consumer_progress.reveal_nik';

    public function up(): void
    {
        $permission = collect(PermissionCatalog::permissions())->firstWhere('slug', self::SLUG);
        if (! $permission) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            ['slug' => self::SLUG],
            [...$permission, 'created_at' => now(), 'updated_at' => now()],
        );

        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        foreach (['branch_manager'] as $roleSlug) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if ($roleId && $permissionId) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        DB::table('role_permission')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('slug', self::SLUG)->delete();
    }
};
