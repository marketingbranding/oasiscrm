<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Pembatasan Lingkup Buku Saku Sales';

    private const REMOVED_GRANTS = [
        'sales' => ['work_planner.%', 'sales_pocketbook.export'],
        'sales_coordinator' => ['sales_pocketbook.export'],
        'supervisor' => ['sales_pocketbook.export'],
    ];

    public function up(): void
    {
        foreach (self::REMOVED_GRANTS as $roleSlug => $permissionPatterns) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if (! $roleId) {
                continue;
            }

            DB::table('role_permission')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', DB::table('permissions')
                    ->where(function ($query) use ($permissionPatterns): void {
                        foreach ($permissionPatterns as $pattern) {
                            str_ends_with($pattern, '%')
                                ? $query->orWhere('slug', 'like', $pattern)
                                : $query->orWhere('slug', $pattern);
                        }
                    })
                    ->pluck('id'))
                ->delete();
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'fixed',
                'description' => 'Batas akses Buku Saku Sales kini memisahkan cakupan Sales, Koordinator, dan Supervisor; Work Planner tidak lagi tersedia untuk Sales dan ekspor monitoring memakai izin tim.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        foreach (self::REMOVED_GRANTS as $roleSlug => $permissionPatterns) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if (! $roleId) {
                continue;
            }

            $permissionIds = DB::table('permissions')
                ->where(function ($query) use ($permissionPatterns): void {
                    foreach ($permissionPatterns as $pattern) {
                        str_ends_with($pattern, '%')
                            ? $query->orWhere('slug', 'like', $pattern)
                            : $query->orWhere('slug', $pattern);
                    }
                })
                ->pluck('id');

            foreach ($permissionIds as $permissionId) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();
    }
};
