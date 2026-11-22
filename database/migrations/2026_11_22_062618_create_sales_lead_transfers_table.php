<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_lead_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_lead_id')->constrained('sales_leads')->restrictOnDelete();
            $table->foreignId('from_sales_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_sales_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['sales_lead_id', 'created_at']);
            $table->index(['to_sales_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_lead_transfers');
    }
};
