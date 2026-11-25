<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Izin rekonsiliasi migrasi konsumen dipisahkan';

    public function up(): void
    {
        $permission = collect(PermissionCatalog::permissions())->firstWhere('slug', 'consumer_migration.reconcile');
        if ($permission === null) {
            throw new RuntimeException('Permission catalog missing [consumer_migration.reconcile].');
        }

        $permissionId = DB::table('permissions')->where('slug', $permission['slug'])->value('id');
        if ($permissionId === null) {
            DB::table('permissions')->insert([
                ...$permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $permissionId = DB::table('permissions')->where('slug', $permission['slug'])->value('id');
        }

        $managePermissionId = DB::table('permissions')->where('slug', 'consumer_progress.manage')->value('id');
        if ($permissionId === null || $managePermissionId === null) {
            throw new RuntimeException('Required permissions missing for consumer migration reconciliation.');
        }

        $roleIds = DB::table('role_permission')
            ->where('permission_id', $managePermissionId)
            ->pluck('role_id');

        foreach ($roleIds as $roleId) {
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
                'description' => 'Akses rekonsiliasi migrasi data konsumen memakai izin terpisah tanpa mengurangi akses role yang sebelumnya memiliki izin mengelola Progress Konsumen.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();
    }
};
