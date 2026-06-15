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
        Schema::create('lead_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('event_id')->nullable()->unique();
            $table->string('project_name');
            $table->string('lead_source');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('total_budget', 15, 0)->nullable();
            $table->integer('daily_target')->nullable();
            $table->enum('status', ['berlangsung', 'selesai'])->default('berlangsung');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_events');
    }
};
