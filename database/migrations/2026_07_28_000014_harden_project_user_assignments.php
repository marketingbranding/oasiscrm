<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_user', function (Blueprint $table) {
            $table->date('assignment_start_date')->nullable();
            $table->date('assignment_end_date')->nullable();
            $table->boolean('is_active')->default(true);
        });

        DB::table('project_user')->update(['is_active' => true]);

        Schema::table('project_user', function (Blueprint $table) {
            $table->index(
                ['user_id', 'is_active', 'assignment_start_date', 'assignment_end_date'],
                'project_user_user_active_dates_index',
            );
            $table->index(['project_id', 'is_active'], 'project_user_project_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('project_user', function (Blueprint $table) {
            $table->dropIndex('project_user_user_active_dates_index');
            $table->dropIndex('project_user_project_active_index');
            $table->dropColumn(['assignment_start_date', 'assignment_end_date', 'is_active']);
        });
    }
};
