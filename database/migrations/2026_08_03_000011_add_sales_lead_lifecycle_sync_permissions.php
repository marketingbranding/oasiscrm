<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUGS = ['sales_pocketbook.sync', 'sales_pocketbook.reconcile'];

    private const CHANGELOG_TITLE = 'Sinkronisasi Siklus Lead Cabang Ditambahkan';

    public function up(): void
    {
        foreach (collect(PermissionCatalog::permissions())->whereIn('slug', self::SLUGS) as $permission) {
            DB::table('permissions')->updateOrInsert(['slug' => $permission['slug']], [
                ...$permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionIds = DB::table('permissions')->whereIn('slug', self::SLUGS)->pluck('id', 'slug');
        foreach (['supervisor', 'manager', 'branch_manager', 'pusat', 'admin'] as $roleSlug) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach (self::SLUGS as $slug) {
                if (! isset($permissionIds[$slug])) {
                    continue;
                }
                DB::table('role_permission')->insertOrIgnore([
                    'permission_id' => $permissionIds[$slug],
                    'role_id' => $roleId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'added',
                'description' => 'Buku Saku Sales kini dapat menarik siklus lead per cabang, mencatat masalah rekonsiliasi, dan mencerminkan Akad secara aman tanpa mencampur cache sinkronisasi modul lain.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();
        DB::table('permissions')->whereIn('slug', self::SLUGS)->delete();
    }
};
