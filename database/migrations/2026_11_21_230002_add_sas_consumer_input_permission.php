<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'SAS mendapat izin input NUP dan Proses Penjualan';

    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'sas')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'consumer_progress.manage')->value('id');

        if ($roleId !== null && $permissionId !== null) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'changed',
                'description' => 'Profil Staff Admin jenis Sales dapat menginput NUP dan Proses Penjualan dalam scope cabang/proyeknya.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'sas')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'consumer_progress.manage')->value('id');

        if ($roleId !== null && $permissionId !== null) {
            DB::table('role_permission')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->delete();
        }

        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();
    }
};
