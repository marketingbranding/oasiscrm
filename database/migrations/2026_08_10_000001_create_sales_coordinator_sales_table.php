<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_coordinator_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coordinator_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sales_user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->timestamps();
            $table->index(['coordinator_user_id', 'is_active', 'started_at', 'ended_at'], 'sales_coordinator_current_index');
            $table->index(['sales_user_id', 'is_active', 'started_at', 'ended_at'], 'sales_user_coordinator_current_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_coordinator_sales');
    }
};
