<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Buku Saku Role Workspace & Local-First Lead';

    private const COORDINATOR_PERMISSIONS = [
        'sales_pocketbook.export_team',
        'sales_pocketbook.export',
        'sales_pocketbook.sync',
    ];

    public function up(): void
    {
        $syncPermission = collect(PermissionCatalog::permissions())->firstWhere('slug', 'sales_pocketbook.sync');
        if ($syncPermission) {
            DB::table('permissions')->where('slug', 'sales_pocketbook.sync')->update([
                'name' => $syncPermission['name'],
                'description' => $syncPermission['description'],
                'group_name' => $syncPermission['group_name'],
                'updated_at' => now(),
            ]);
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', [...self::COORDINATOR_PERMISSIONS, 'sales_pocketbook.sync'])
            ->pluck('id', 'slug');
        $salesRoleId = DB::table('roles')->where('slug', 'sales')->value('id');
        if ($salesRoleId && isset($permissionIds['sales_pocketbook.sync'])) {
            DB::table('role_permission')
                ->where('role_id', $salesRoleId)
                ->where('permission_id', $permissionIds['sales_pocketbook.sync'])
                ->delete();
        }

        $coordinatorRoleId = DB::table('roles')->where('slug', 'sales_coordinator')->value('id');
        if ($coordinatorRoleId) {
            foreach (self::COORDINATOR_PERMISSIONS as $slug) {
                if (! isset($permissionIds[$slug])) {
                    continue;
                }

                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $coordinatorRoleId,
                    'permission_id' => $permissionIds[$slug],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'changed',
                'description' => 'Workspace Buku Saku kini mengikuti peran utama: Sales bekerja pada agenda milik sendiri, sedangkan Koordinator Sales mengelola dan mengekspor data tim serta mendorong lead lokal ke spreadsheet cabang.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', self::COORDINATOR_PERMISSIONS)
            ->pluck('id', 'slug');
        $coordinatorRoleId = DB::table('roles')->where('slug', 'sales_coordinator')->value('id');
        if ($coordinatorRoleId) {
            DB::table('role_permission')
                ->where('role_id', $coordinatorRoleId)
                ->whereIn('permission_id', $permissionIds->values())
                ->delete();
        }

        $salesRoleId = DB::table('roles')->where('slug', 'sales')->value('id');
        if ($salesRoleId && isset($permissionIds['sales_pocketbook.sync'])) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $salesRoleId,
                'permission_id' => $permissionIds['sales_pocketbook.sync'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('permissions')->where('slug', 'sales_pocketbook.sync')->update([
            'name' => 'Menyinkronkan siklus lead Buku Saku Sales',
            'description' => 'Menarik dan menyinkronkan siklus lead dari spreadsheet cabang sesuai lingkup akses.',
            'updated_at' => now(),
        ]);

        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();
    }
};
