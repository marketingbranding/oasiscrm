<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUGS = [
        'comments.view',
        'comments.create',
        'comments.reply',
        'comments.update_own',
        'comments.delete_own',
        'comments.moderate',
        'comments.view_history',
        'comments.mention',
    ];

    public function up(): void
    {
        $permissions = collect(PermissionCatalog::permissions())
            ->whereIn('slug', self::SLUGS);

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(['slug' => $permission['slug']], [
                ...$permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionIds = DB::table('permissions')->whereIn('slug', self::SLUGS)->pluck('id', 'slug');
        $mappings = PermissionCatalog::rolePermissions();
        $mappings['superadmin'] = $permissionIds->keys()->all();

        foreach ($mappings as $roleSlug => $permissionSlugs) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if (! $roleId) {
                continue;
            }

            foreach (array_intersect($permissionSlugs, $permissionIds->keys()->all()) as $slug) {
                DB::table('role_permission')->insertOrIgnore([
                    'permission_id' => $permissionIds[$slug],
                    'role_id' => $roleId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('slug', self::SLUGS)->delete();
    }
};
