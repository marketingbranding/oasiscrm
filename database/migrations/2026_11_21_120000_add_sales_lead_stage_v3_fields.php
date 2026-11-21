<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->string('lead_stage', 30)->nullable()->index();
            $table->timestamp('lead_stage_changed_at')->nullable();
            $table->timestamp('gugur_at')->nullable();
            $table->string('gugur_reason')->nullable();
        });

        Schema::create('sales_lead_stage_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_stage', 30)->nullable();
            $table->string('to_stage', 30);
            $table->string('source', 60);
            $table->string('source_id')->nullable();
            $table->uuid('operation_uuid')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('changed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['sales_lead_id', 'changed_at'], 'lead_stage_history_lead_changed_index');
            $table->unique(['branch_id', 'operation_uuid'], 'lead_stage_history_operation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_lead_stage_histories');

        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropColumn(['lead_stage', 'lead_stage_changed_at', 'gugur_at', 'gugur_reason']);
        });
    }
};
