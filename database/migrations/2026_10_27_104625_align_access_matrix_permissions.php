<?php

use App\Models\Permission;
use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NEW_PERMISSIONS = ['lead_sources.manage', 'feedback_reports.review'];

    private const RESTRICTED_ROLE_SLUGS = ['admin', 'branch_manager'];

    private const RESTRICTED_PERMISSION_PREFIXES = ['expenses.', 'sales_pocketbook.manage_'];

    private const CHANGELOG_TITLE = 'Penyelarasan Matriks Akses OASIS';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (collect(PermissionCatalog::permissions())->whereIn('slug', self::NEW_PERMISSIONS) as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                [...$permission, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $this->removeRestrictedRolePermissions();
        Permission::resetRegisteredSlugs();

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'fixed',
                'description' => 'Akses sumber lead dan peninjauan feedback kini menggunakan izin terdaftar; Admin dan Branch Manager tidak lagi menerima Pengeluaran atau mutasi Buku Saku Sales.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->restoreRestrictedRolePermissions();

        DB::table('permissions')->whereIn('slug', self::NEW_PERMISSIONS)->delete();
        Permission::resetRegisteredSlugs();
        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();
    }

    private function removeRestrictedRolePermissions(): void
    {
        $roleIds = DB::table('roles')->whereIn('slug', self::RESTRICTED_ROLE_SLUGS)->pluck('id');
        $permissionIds = $this->restrictedPermissionIds();

        DB::table('role_permission')
            ->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', $permissionIds)
            ->delete();
    }

    private function restoreRestrictedRolePermissions(): void
    {
        $roleIds = DB::table('roles')->whereIn('slug', self::RESTRICTED_ROLE_SLUGS)->pluck('id');
        $permissionIds = $this->restrictedPermissionIds();

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function restrictedPermissionIds(): Collection
    {
        return DB::table('permissions')
            ->where(function ($query): void {
                foreach (self::RESTRICTED_PERMISSION_PREFIXES as $prefix) {
                    $query->orWhere('slug', 'like', $prefix.'%');
                }
            })
            ->pluck('id');
    }
};
