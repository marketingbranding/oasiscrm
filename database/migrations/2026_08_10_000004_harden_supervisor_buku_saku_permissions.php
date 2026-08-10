<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Buku Saku Supervisor Monitoring';

    private const REQUIRED_PERMISSIONS = [
        'sales_pocketbook.view_team',
        'sales_pocketbook.view_assigned',
        'sales_pocketbook.export_team',
        'sales_pocketbook.export_assigned',
        'sales_pocketbook.export',
    ];

    private const FORBIDDEN_PERMISSIONS = [
        'sales_pocketbook.view_own',
        'sales_pocketbook.manage_own',
        'sales_pocketbook.manage_team',
        'sales_pocketbook.manage_assigned',
        'sales_pocketbook.export_own',
        'sales_pocketbook.sync',
        'sales_pocketbook.reconcile',
    ];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'supervisor')->value('id');
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', [...self::REQUIRED_PERMISSIONS, ...self::FORBIDDEN_PERMISSIONS])
            ->pluck('id', 'slug');

        if ($roleId) {
            DB::table('role_permission')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $permissionIds->only(self::FORBIDDEN_PERMISSIONS)->values())
                ->delete();

            foreach (self::REQUIRED_PERMISSIONS as $slug) {
                if (isset($permissionIds[$slug])) {
                    DB::table('role_permission')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $permissionIds[$slug],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'changed',
                'description' => 'Supervisor kini memantau dan mengekspor Buku Saku Sales untuk tim dan penugasan tanpa izin mengelola, menyinkronkan, atau merekonsiliasi data.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'supervisor')->value('id');
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', [...self::REQUIRED_PERMISSIONS, ...self::FORBIDDEN_PERMISSIONS])
            ->pluck('id', 'slug');

        if ($roleId) {
            DB::table('role_permission')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $permissionIds->only(self::REQUIRED_PERMISSIONS)->values())
                ->delete();

            foreach (self::FORBIDDEN_PERMISSIONS as $slug) {
                if (isset($permissionIds[$slug])) {
                    DB::table('role_permission')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $permissionIds[$slug],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();
    }
};
