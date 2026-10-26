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
        Schema::table('sales_daily_plan_items', function (Blueprint $table): void {
            $table->string('sales_activity_category')->nullable()->after('position');
            $table->text('location')->nullable()->after('activity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_daily_plan_items', function (Blueprint $table): void {
            $table->dropColumn(['sales_activity_category', 'location']);
        });
    }
};
