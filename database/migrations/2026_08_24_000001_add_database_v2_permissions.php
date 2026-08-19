<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = collect(PermissionCatalog::permissions())
            ->filter(fn ($p) => str_starts_with($p['slug'], 'database_v2.'))
            ->all();

        foreach ($permissions as $perm) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $perm['slug']],
                array_merge($perm, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        $permissionIds = DB::table('permissions')
            ->where('slug', 'like', 'database_v2.%')
            ->pluck('id', 'slug');

        $rolePermissions = PermissionCatalog::rolePermissions();

        foreach ($rolePermissions as $roleSlug => $permissionSlugs) {
            if ($permissionSlugs === null) {
                continue;
            }

            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if (! $roleId) {
                continue;
            }

            foreach ($permissionSlugs as $slug) {
                if (! str_starts_with($slug, 'database_v2.')) {
                    continue;
                }
                $permissionId = $permissionIds->get($slug);
                if ($permissionId) {
                    DB::table('role_permission')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->where('slug', 'like', 'database_v2.%')->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->where('slug', 'like', 'database_v2.%')->delete();
    }
};
