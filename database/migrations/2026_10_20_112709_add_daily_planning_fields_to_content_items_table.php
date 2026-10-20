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
        Schema::table('content_items', function (Blueprint $table) {
            $table->foreignId('daily_plan_id')->nullable()->after('sales_project_id')->constrained('sales_daily_plans')->nullOnDelete();
            $table->unsignedInteger('sort_order')->nullable()->after('duration_minutes');

            $table->index(['daily_plan_id', 'sort_order'], 'content_items_daily_plan_order_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropIndex('content_items_daily_plan_order_index');
            $table->dropConstrainedForeignId('daily_plan_id');
            $table->dropColumn('sort_order');
        });
    }
};
