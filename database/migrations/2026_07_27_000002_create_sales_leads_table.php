<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained('lead_master')->restrictOnDelete();
            $table->foreignId('sales_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->date('lead_date');
            $table->string('customer_name');
            $table->string('phone', 50)->nullable();
            $table->string('normalized_phone', 30)->nullable()->index();
            $table->string('source_name_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->string('linked_consumer_reference')->nullable();
            $table->timestamp('contacted_at')->nullable()->index();
            $table->timestamp('met_at')->nullable()->index();
            $table->timestamp('surveyed_at')->nullable()->index();
            $table->timestamp('utj_at')->nullable()->index();
            $table->timestamp('documents_completed_at')->nullable()->index();
            $table->timestamp('akad_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'lead_date']);
            $table->index(['sales_user_id', 'lead_date']);
            $table->index(['project_id', 'lead_date']);
            $table->index(['branch_id', 'sales_user_id', 'lead_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_leads');
    }
};
