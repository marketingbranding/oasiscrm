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
        Schema::create('sales_daily_plan_item_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_item_id')->constrained('sales_daily_plan_items')->restrictOnDelete();
            $table->foreignId('target_item_id')->constrained('sales_daily_plan_items')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->date('source_work_date');
            $table->date('target_work_date');
            $table->text('reason');
            $table->timestamps();

            $table->unique('source_item_id');
            $table->unique('target_item_id');
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_daily_plan_item_reschedules');
    }
};
