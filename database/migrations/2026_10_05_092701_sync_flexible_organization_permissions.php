<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['organization.view', 'organization.move_user', 'organization.view_history'])
            ->pluck('id', 'slug');

        foreach (['pusat', 'branch_manager'] as $roleSlug) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if ($roleId === null) {
                continue;
            }

            foreach ($permissionIds as $permissionId) {
                DB::table('role_permission')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        $roleIds = DB::table('roles')->whereIn('slug', ['pusat', 'branch_manager'])->pluck('id');
        $permissionIds = DB::table('permissions')->whereIn('slug', ['organization.view', 'organization.move_user', 'organization.view_history'])->pluck('id');

        DB::table('role_permission')
            ->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', $permissionIds)
            ->delete();
    }
};
