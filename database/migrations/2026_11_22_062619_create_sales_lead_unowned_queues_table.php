<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_lead_unowned_queues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_lead_id')->constrained('sales_leads')->restrictOnDelete();
            $table->foreignId('former_sales_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('queue_coordinator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_active_coordinator_user_id')->nullable()->constrained('users', indexName: 'slu_queues_last_active_coord_fk')->nullOnDelete();
            $table->string('status')->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sales_lead_id', 'status']);
            $table->index(['queue_coordinator_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_lead_unowned_queues');
    }
};
