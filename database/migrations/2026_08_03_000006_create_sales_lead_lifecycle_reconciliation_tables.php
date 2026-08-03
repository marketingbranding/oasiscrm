<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_lead_lifecycle_sync_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 40)->default('never');
            $table->uuid('operation_uuid')->nullable();
            $table->text('message')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_successful_at')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sales_lead_lifecycle_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id');
            $table->foreignId('sales_lead_id')->nullable();
            $table->uuid('operation_uuid')->nullable();
            $table->string('entity_type', 60);
            $table->string('identity_key');
            $table->string('issue_code', 80);
            $table->string('status', 40)->default('open');
            $table->json('metadata')->nullable();
            $table->foreignId('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('branch_id', 'lead_lifecycle_recon_branch_foreign')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('sales_lead_id', 'lead_lifecycle_recon_lead_foreign')->references('id')->on('sales_leads')->nullOnDelete();
            $table->foreign('resolved_by', 'lead_lifecycle_recon_resolver_foreign')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'status'], 'lead_lifecycle_recon_status_index');
            $table->index(['branch_id', 'operation_uuid'], 'lead_lifecycle_recon_operation_index');
            $table->unique(['branch_id', 'entity_type', 'identity_key', 'issue_code'], 'lead_lifecycle_reconciliation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_lead_lifecycle_reconciliation_items');
        Schema::dropIfExists('sales_lead_lifecycle_sync_statuses');
    }
};
