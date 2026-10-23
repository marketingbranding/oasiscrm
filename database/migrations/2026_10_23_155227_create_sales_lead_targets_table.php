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
        Schema::create('sales_lead_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_user_id')->constrained('users')->restrictOnDelete();
            $table->date('target_month');
            $table->unsignedInteger('target_leads');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sales_user_id', 'target_month']);
            $table->index('target_month');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_lead_targets');
    }
};
