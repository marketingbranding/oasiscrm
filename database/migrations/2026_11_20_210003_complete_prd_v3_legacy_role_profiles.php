<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Lengkapi profil izin role legacy PRD v3';

    public function up(): void
    {
        foreach ([
            ['slug' => 'admin', 'name' => 'Staff Admin · Admin Cabang', 'description' => 'Profil izin legacy untuk Staff Admin bertipe Admin Cabang.'],
            ['slug' => 'sas', 'name' => 'Staff Admin · Sales', 'description' => 'Profil izin legacy untuk Staff Admin bertipe Sales.'],
            ['slug' => 'sam', 'name' => 'Staff Admin · Marketing', 'description' => 'Profil izin legacy untuk Staff Admin bertipe Marketing.'],
            ['slug' => 'staff', 'name' => 'Staff Admin · Umum', 'description' => 'Profil izin legacy untuk Staff Admin bertipe Umum.'],
        ] as $role) {
            DB::table('roles')->where('slug', $role['slug'])->update([
                'name' => $role['name'],
                'description' => $role['description'],
                'updated_at' => now(),
            ]);
        }

        $permissionIds = DB::table('permissions')->pluck('id', 'slug');
        foreach (['admin', 'sas', 'sam', 'staff'] as $roleSlug) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if (! $roleId) {
                continue;
            }

            DB::table('role_permission')->where('role_id', $roleId)->delete();
            foreach (PermissionCatalog::rolePermissions()[$roleSlug] ?? [] as $permissionSlug) {
                $permissionId = $permissionIds[$permissionSlug] ?? null;
                if (! $permissionId) {
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

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'category' => 'changed',
                'description' => 'Profil role legacy admin, sas, sam, dan staff memakai pemetaan permission database yang sama pada instalasi baru maupun database lama.',
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
