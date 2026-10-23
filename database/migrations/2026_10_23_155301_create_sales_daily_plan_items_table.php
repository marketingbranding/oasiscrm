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
        Schema::create('sales_daily_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_daily_plan_id')->constrained('sales_daily_plans')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('activity');
            $table->text('detail')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->time('start_time');
            $table->time('end_time');
            $table->text('activity_result')->nullable();
            $table->string('status', 20)->default('planned');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sales_daily_plan_id', 'position']);
            $table->index(['sales_daily_plan_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_daily_plan_items');
    }
};
