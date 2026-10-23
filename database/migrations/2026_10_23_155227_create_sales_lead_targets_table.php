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
        Schema::table('sales_lead_targets', function (Blueprint $table): void {
            $table->date('target_month')->nullable()->after('period_month');
            $table->unsignedInteger('target_leads')->nullable()->after('target_month');
            $table->unique(['sales_user_id', 'target_month'], 'sales_lead_targets_sales_target_month_unique');
            $table->index('target_month', 'sales_lead_targets_target_month_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_lead_targets', function (Blueprint $table): void {
            $table->dropUnique('sales_lead_targets_sales_target_month_unique');
            $table->dropIndex('sales_lead_targets_target_month_index');
            $table->dropColumn(['target_month', 'target_leads']);
        });
    }
};
