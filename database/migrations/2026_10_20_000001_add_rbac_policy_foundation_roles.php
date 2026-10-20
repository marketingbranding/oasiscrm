<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Fondasi Kebijakan RBAC CRM';

    public function up(): void
    {
        foreach ([
            ['slug' => 'sas', 'name' => 'Staff Admin Sales', 'description' => 'Administrasi Sales tingkat cabang dengan akses monitoring.'],
            ['slug' => 'sam', 'name' => 'Staff Admin Marketing', 'description' => 'Administrasi Marketing tingkat cabang dengan akses monitoring.'],
        ] as $role) {
            DB::table('roles')->updateOrInsert(['slug' => $role['slug']], [
                'name' => $role['name'],
                'description' => $role['description'],
                'is_superadmin' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (PermissionCatalog::permissions() as $permission) {
            DB::table('permissions')->updateOrInsert(['slug' => $permission['slug']], [
                ...$permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionIds = DB::table('permissions')->pluck('id', 'slug');
        foreach (['sas', 'sam'] as $roleSlug) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            DB::table('role_permission')->where('role_id', $roleId)->delete();

            foreach (PermissionCatalog::rolePermissions()[$roleSlug] as $permissionSlug) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionIds[$permissionSlug],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $supervisorId = DB::table('roles')->where('slug', 'supervisor')->value('id');
        foreach (['sales_pocketbook.view_branch', 'sales_pocketbook.export_branch'] as $permissionSlug) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $supervisorId,
                'permission_id' => $permissionIds[$permissionSlug],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'changed',
                'description' => 'Fondasi otorisasi CRM kini menggunakan pola peran, izin, lingkup, kebijakan, dan record. Staff Admin Sales serta Staff Admin Marketing tersedia sebagai peran cabang dengan pemetaan izin monitoring Buku Saku Sales yang identik. Otoritas TEAM Koordinator Sales menggunakan pemetaan sales_coordinator_sales yang aktif dan berlaku. Fitur target lead, Daily Plan, aturan 420 menit, notifikasi terjadwal, dan perubahan wajib nomor telepon belum termasuk.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();

        foreach (['sas', 'sam'] as $roleSlug) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if (! $roleId) {
                continue;
            }

            DB::table('role_permission')->where('role_id', $roleId)->delete();
            if (! DB::table('users')->where('role_id', $roleId)->exists()
                && ! DB::table('role_user')->where('role_id', $roleId)->exists()) {
                DB::table('roles')->where('id', $roleId)->delete();
            }
        }
    }
};
