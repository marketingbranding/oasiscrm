<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionCatalog::permissions() as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                [...$permission, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        // The new organization permissions are registered first and deliberately
        // remain unassigned until role administration has an explicit rollout.
        // Superadmin access is covered by the registered-permission wildcard.
    }

    public function down(): void
    {
        $slugs = [
            'organization.view', 'organization.move_user', 'organization.view_history',
            'organization.manage', 'organization.configure_rules', 'roles.view',
            'roles.create', 'roles.update', 'roles.assign_permissions',
        ];
        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
