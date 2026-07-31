<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION_SLUGS = ['users.anonymize', 'users.release_email'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'anonymized_at')) {
                $table->timestamp('anonymized_at')->nullable();
            }
        });

        $permissionIds = [];
        foreach (collect(PermissionCatalog::permissions())->whereIn('slug', self::PERMISSION_SLUGS) as $permission) {
            DB::table('permissions')->updateOrInsert(['slug' => $permission['slug']], [
                ...$permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $permissionIds[] = DB::table('permissions')->where('slug', $permission['slug'])->value('id');
        }

        foreach (['superadmin', 'pusat'] as $roleSlug) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permission')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::PERMISSION_SLUGS as $slug) {
            DB::table('role_permission')->where('permission_id', DB::table('permissions')->where('slug', $slug)->value('id'))->delete();
            DB::table('permissions')->where('slug', $slug)->delete();
        }
        if (Schema::hasColumn('users', 'anonymized_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('anonymized_at'));
        }
    }
};
