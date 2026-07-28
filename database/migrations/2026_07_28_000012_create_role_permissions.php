<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'is_active')) {
            Schema::table('roles', fn (Blueprint $table) => $table->boolean('is_active')->default(true));
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('group_name');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('role_permission')) {
            Schema::create('role_permission', function (Blueprint $table) {
                $table->id();
                $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['permission_id', 'role_id']);
            });
        }

        $roles = [
            ['slug' => 'sales', 'name' => 'Sales', 'description' => 'Tim penjualan dengan akses ke data sendiri.'],
            ['slug' => 'sales_coordinator', 'name' => 'Koordinator Sales', 'description' => 'Koordinator penjualan dengan akses ke data tim.'],
            ['slug' => 'supervisor', 'name' => 'Supervisor', 'description' => 'Supervisor dengan akses tim dan penugasan operasional.'],
            ['slug' => 'manager', 'name' => 'Manager', 'description' => 'Manager dengan akses pemantauan berdasarkan penugasan.'],
            ['slug' => 'branch_manager', 'name' => 'Branch Manager', 'description' => 'Pimpinan cabang dengan akses operasional tingkat cabang.'],
            ['slug' => 'pusat', 'name' => 'Tim Pusat', 'description' => 'Tim pusat dengan akses operasional lintas cabang.'],
            ['slug' => 'superadmin', 'name' => 'Super Admin', 'description' => 'Administrator sistem dengan seluruh izin.'],
            ['slug' => 'admin', 'name' => 'Admin', 'description' => 'Peran lama untuk administrasi operasional cabang.'],
            ['slug' => 'staff', 'name' => 'Staff', 'description' => 'Peran lama untuk pelaksana operasional cabang.'],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(['slug' => $role['slug']], [
                'name' => $role['name'],
                'description' => $role['description'],
                'is_superadmin' => $role['slug'] === 'superadmin',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('roles')->where('slug', '!=', 'superadmin')->update(['is_superadmin' => false]);

        foreach (PermissionCatalog::permissions() as $permission) {
            DB::table('permissions')->updateOrInsert(['slug' => $permission['slug']], [
                ...$permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionIds = DB::table('permissions')->pluck('id', 'slug');
        $mappings = PermissionCatalog::rolePermissions();
        $mappings['superadmin'] = $permissionIds->keys()->all();

        foreach ($mappings as $roleSlug => $permissionSlugs) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            DB::table('role_permission')->where('role_id', $roleId)->delete();
            DB::table('role_permission')->insert(collect($permissionSlugs)->map(fn (string $slug) => [
                'permission_id' => $permissionIds[$slug],
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        if (Schema::hasColumn('roles', 'is_active')) {
            Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('is_active'));
        }
    }
};
