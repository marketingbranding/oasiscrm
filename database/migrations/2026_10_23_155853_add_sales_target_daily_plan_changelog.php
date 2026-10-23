<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permission = [
            'name' => 'Mengatur target Lead Sales',
            'slug' => 'sales_pocketbook.targets_manage',
            'description' => 'Menetapkan target Lead bulanan per Sales.',
            'group_name' => 'Buku Saku Sales',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('permissions')->updateOrInsert(['slug' => $permission['slug']], $permission);
        $permissionId = DB::table('permissions')->where('slug', $permission['slug'])->value('id');
        $superadminId = DB::table('roles')->where('slug', 'superadmin')->value('id');
        if ($permissionId && $superadminId) {
            DB::table('role_permission')->updateOrInsert([
                'permission_id' => $permissionId,
                'role_id' => $superadminId,
            ], ['updated_at' => now(), 'created_at' => now()]);
        }
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Target Lead dan Daily Plan Sales'],
            [
                'description' => 'Buku Saku Sales kini mendukung target Lead bulanan per PIC Sales, Daily Plan Senin–Jumat dengan 420 menit efektif, penguncian setelah submit, revisi Coordinator, dan pengingat aktivitas in-app.',
                'category' => 'added',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Target Lead dan Daily Plan Sales')->delete();
        $permissionId = DB::table('permissions')->where('slug', 'sales_pocketbook.targets_manage')->value('id');
        if ($permissionId) {
            DB::table('role_permission')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
