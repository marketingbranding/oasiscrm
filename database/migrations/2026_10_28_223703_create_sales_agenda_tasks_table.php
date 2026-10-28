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
        Schema::create('sales_agenda_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->string('title');
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['content_item_id', 'is_completed'], 'sales_agenda_tasks_agenda_completed_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_agenda_tasks');
    }
};
