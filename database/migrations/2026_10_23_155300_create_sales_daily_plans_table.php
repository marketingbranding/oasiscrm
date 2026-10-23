<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_daily_plans', function (Blueprint $table): void {
            $table->date('work_date')->nullable()->after('plan_date');
            $table->timestamp('revised_at')->nullable()->after('submitted_by');
            $table->foreignId('revised_by')->nullable()->after('revised_at')->constrained('users')->nullOnDelete();
            $table->unique(['sales_user_id', 'work_date'], 'sales_daily_plans_sales_work_date_unique');
            $table->index(['work_date', 'status'], 'sales_daily_plans_work_date_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_daily_plans', function (Blueprint $table): void {
            $table->dropUnique('sales_daily_plans_sales_work_date_unique');
            $table->dropIndex('sales_daily_plans_work_date_status_index');
            $table->dropConstrainedForeignId('revised_by');
            $table->dropColumn(['work_date', 'revised_at']);
        });
    }
};
