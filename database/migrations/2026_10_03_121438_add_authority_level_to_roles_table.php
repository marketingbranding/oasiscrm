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
        if (! Schema::hasColumn('roles', 'authority_level')) {
            Schema::table('roles', fn (Blueprint $table) => $table->unsignedInteger('authority_level')->default(0)->after('is_superadmin'));
        }

        $levels = [
            'sales' => 10,
            'staff' => 10,
            'sales_coordinator' => 20,
            'supervisor' => 30,
            'admin' => 30,
            'manager' => 40,
            'branch_manager' => 50,
            'pusat' => 90,
            'superadmin' => 100,
        ];

        foreach ($levels as $slug => $level) {
            DB::table('roles')->where('slug', $slug)->update(['authority_level' => $level]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('roles', 'authority_level')) {
            Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('authority_level'));
        }
    }
};
