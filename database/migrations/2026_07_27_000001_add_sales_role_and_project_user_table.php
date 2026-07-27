<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->updateOrInsert(
            ['slug' => 'sales'],
            [
                'name' => 'Sales',
                'description' => 'Sales user with access to personal Buku Saku and assigned projects.',
                'is_superadmin' => false,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        if (! Schema::hasTable('project_user')) {
            Schema::create('project_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('project_id')->constrained('lead_master')->cascadeOnDelete();
                $table->boolean('is_primary')->default(false);
                $table->timestamps();

                $table->unique(['user_id', 'project_id']);
                $table->index('project_id');
                $table->index(['user_id', 'is_primary']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_user');

        $roleId = DB::table('roles')
            ->where('slug', 'sales')
            ->where('name', 'Sales')
            ->where('description', 'Sales user with access to personal Buku Saku and assigned projects.')
            ->value('id');

        if ($roleId && ! DB::table('users')->where('role_id', $roleId)->exists()) {
            DB::table('roles')->where('id', $roleId)->delete();
        }
    }
};
