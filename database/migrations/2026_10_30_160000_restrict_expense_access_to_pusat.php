<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROLE_SLUG = 'supervisor';

    private const PERMISSION_SLUGS = [
        'expenses.view',
        'expenses.create',
        'expenses.update',
        'expenses.cancel',
        'expenses.export',
    ];

    private const CHANGELOG_TITLE = 'Akses Pengeluaran untuk Operasional Pusat';

    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', self::ROLE_SLUG)->value('id');
        $permissionIds = DB::table('permissions')->whereIn('slug', self::PERMISSION_SLUGS)->pluck('id');

        if ($roleId !== null && $permissionIds->isNotEmpty()) {
            DB::table('role_permission')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'changed',
                'description' => 'Modul Pengeluaran kini dibatasi untuk operasional Tim Pusat; akses Super Admin tetap dipertahankan.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', self::ROLE_SLUG)->value('id');
        $permissionIds = DB::table('permissions')->whereIn('slug', self::PERMISSION_SLUGS)->pluck('id');

        if ($roleId !== null) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', self::CHANGELOG_TITLE)
            ->delete();
    }
};
