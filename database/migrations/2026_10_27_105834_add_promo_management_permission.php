<?php

use App\Models\Permission;
use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUG = 'promo.manage';

    private const ROLES = ['admin', 'branch_manager'];

    private const CHANGELOG_TITLE = 'Akses pengelolaan Promo dipisahkan dari Buku Saku';

    public function up(): void
    {
        $permission = collect(PermissionCatalog::permissions())->firstWhere('slug', self::SLUG);

        if ($permission !== null) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => self::SLUG],
                [...$permission, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');

        if ($permissionId !== null) {
            foreach (self::ROLES as $roleSlug) {
                $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');

                if ($roleId === null) {
                    continue;
                }

                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Permission::resetRegisteredSlugs();

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'changed',
                'description' => 'Akses pengelolaan Promo Admin dan Branch Manager kini memakai izin Promo khusus tanpa mengembalikan izin mutasi lead atau agenda Buku Saku Sales.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');

        if ($permissionId !== null) {
            DB::table('role_permission')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        Permission::resetRegisteredSlugs();
        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();
    }
};
