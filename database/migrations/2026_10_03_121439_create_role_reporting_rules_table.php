<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('role_reporting_rules')) {
            Schema::create('role_reporting_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_role_id')->constrained('roles')->cascadeOnDelete();
                $table->foreignId('child_role_id')->constrained('roles')->cascadeOnDelete();
                $table->boolean('is_allowed')->default(true);
                $table->timestamps();
                $table->unique(['parent_role_id', 'child_role_id']);
                $table->index(['parent_role_id', 'is_allowed']);
                $table->index(['child_role_id', 'is_allowed']);
            });
        }

        $allowed = [
            'superadmin' => ['sales', 'staff', 'sales_coordinator', 'supervisor', 'admin', 'manager', 'branch_manager', 'pusat'],
            'pusat' => ['sales', 'staff', 'sales_coordinator', 'supervisor', 'admin', 'manager', 'branch_manager'],
            'branch_manager' => ['sales', 'staff', 'sales_coordinator', 'supervisor', 'admin', 'manager'],
            'manager' => ['sales', 'staff', 'sales_coordinator', 'supervisor', 'admin'],
            'supervisor' => ['sales', 'staff', 'sales_coordinator', 'supervisor'],
            'sales_coordinator' => ['sales'],
            'admin' => ['sales', 'staff'],
        ];

        foreach ($allowed as $parentSlug => $childSlugs) {
            $parentId = DB::table('roles')->where('slug', $parentSlug)->value('id');
            if ($parentId === null) {
                continue;
            }

            foreach ($childSlugs as $childSlug) {
                $childId = DB::table('roles')->where('slug', $childSlug)->value('id');
                if ($childId === null) {
                    continue;
                }

                DB::table('role_reporting_rules')->updateOrInsert(
                    ['parent_role_id' => $parentId, 'child_role_id' => $childId],
                    ['is_allowed' => true, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_reporting_rules');
    }
};
