<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Workspace Database Konsumen Lama Dihapus';

    private const SLUGS = [
        'database_v2.view',
        'database_v2.edit',
        'database_v2.export',
        'database_v2.view_branch',
        'database_v2.manage_branch',
        'database_v2.export_branch',
        'database_v2.view_all',
        'database_v2.manage_all',
        'database_v2.export_all',
        'database_v2.configure',
        'database_v2.delete_permanently',
    ];

    public function up(): void
    {
        $this->createArchives();
        $permissions = DB::table('permissions')->whereIn('slug', self::SLUGS)->get();

        foreach ($permissions as $permission) {
            DB::table('database_v2_permission_archives')->updateOrInsert(
                ['slug' => $permission->slug],
                [
                    'name' => $permission->name,
                    'description' => $permission->description,
                    'group_name' => $permission->group_name,
                    'created_at' => $permission->created_at,
                    'updated_at' => $permission->updated_at,
                ],
            );
        }

        foreach (DB::table('role_permission')->whereIn('permission_id', $permissions->pluck('id'))->get() as $mapping) {
            $slug = $permissions->firstWhere('id', $mapping->permission_id)?->slug;
            if ($slug) {
                DB::table('database_v2_role_permission_archives')->updateOrInsert(
                    ['role_id' => $mapping->role_id, 'permission_slug' => $slug],
                    ['created_at' => $mapping->created_at, 'updated_at' => $mapping->updated_at],
                );
            }
        }

        $permissionIds = $permissions->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'description' => 'Menu Data Konsumen, Database Baru, dan Database V2 telah dihapus. Database dan Konsumen Progress tetap tersedia, sedangkan data serta fondasi konsumen tetap dipertahankan.',
                'category' => 'removed',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('database_v2_permission_archives')) {
            foreach (DB::table('database_v2_permission_archives')->get() as $permission) {
                DB::table('permissions')->updateOrInsert(
                    ['slug' => $permission->slug],
                    [
                        'name' => $permission->name,
                        'description' => $permission->description,
                        'group_name' => $permission->group_name,
                        'created_at' => $permission->created_at,
                        'updated_at' => $permission->updated_at,
                    ],
                );
            }
        }

        if (Schema::hasTable('database_v2_role_permission_archives')) {
            foreach (DB::table('database_v2_role_permission_archives')->get() as $mapping) {
                $permissionId = DB::table('permissions')->where('slug', $mapping->permission_slug)->value('id');
                if ($permissionId && DB::table('roles')->where('id', $mapping->role_id)->exists()) {
                    DB::table('role_permission')->insertOrIgnore([
                        'role_id' => $mapping->role_id,
                        'permission_id' => $permissionId,
                        'created_at' => $mapping->created_at,
                        'updated_at' => $mapping->updated_at,
                    ]);
                }
            }
        }

        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();
        Schema::dropIfExists('database_v2_role_permission_archives');
        Schema::dropIfExists('database_v2_permission_archives');
    }

    private function createArchives(): void
    {
        if (! Schema::hasTable('database_v2_permission_archives')) {
            Schema::create('database_v2_permission_archives', function (Blueprint $table) {
                $table->string('slug')->primary();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('group_name')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('database_v2_role_permission_archives')) {
            Schema::create('database_v2_role_permission_archives', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('permission_slug');
                $table->timestamps();
                $table->primary(['role_id', 'permission_slug']);
            });
        }
    }
};
