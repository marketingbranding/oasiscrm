<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_lead_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 40);
            $table->string('source', 60);
            $table->string('source_id')->nullable();
            $table->uuid('operation_uuid')->nullable();
            $table->timestamp('changed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['sales_lead_id', 'changed_at'], 'lead_status_history_lead_changed_index');
            $table->unique(['branch_id', 'operation_uuid'], 'lead_status_history_operation_unique');
            $table->unique(['sales_lead_id', 'source', 'source_id', 'status'], 'lead_status_history_source_unique');
        });

        Schema::create('sales_lead_site_visits', function (Blueprint $table) {
            $this->linkedColumns($table);
            $table->timestamp('visited_at')->nullable();
            $table->string('outcome')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_lead_consumer_links', function (Blueprint $table) {
            $this->linkedColumns($table);
            $table->string('consumer_id')->nullable();
            $table->string('consumer_reference')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_lead_slik_attempts', function (Blueprint $table) {
            $this->linkedColumns($table);
            $table->string('nik', 32)->nullable();
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->timestamp('checked_at')->nullable();
            $table->string('result')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_lead_freelance_links', function (Blueprint $table) {
            $this->linkedColumns($table);
            $table->foreignId('freelancer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('freelance_id')->nullable();
            $table->string('freelance_reference')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_lead_akad_links', function (Blueprint $table) {
            $this->linkedColumns($table);
            $table->string('akad_id')->nullable();
            $table->string('akad_reference')->nullable();
            $table->timestamp('akad_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_lead_akad_links');
        Schema::dropIfExists('sales_lead_freelance_links');
        Schema::dropIfExists('sales_lead_slik_attempts');
        Schema::dropIfExists('sales_lead_consumer_links');
        Schema::dropIfExists('sales_lead_site_visits');
        Schema::dropIfExists('sales_lead_status_histories');
    }

    private function linkedColumns(Blueprint $table): void
    {
        $key = match ($table->getTable()) {
            'sales_lead_site_visits' => 'lead_visit',
            'sales_lead_consumer_links' => 'lead_consumer',
            'sales_lead_slik_attempts' => 'lead_slik',
            'sales_lead_freelance_links' => 'lead_freelance',
            'sales_lead_akad_links' => 'lead_akad',
        };
        $table->id();
        $table->foreignId('sales_lead_id')->constrained()->cascadeOnDelete();
        $table->foreignId('branch_id')->constrained()->restrictOnDelete();
        $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
        $table->uuid('operation_uuid')->nullable();
        $table->string('oasis_sync_id')->nullable();
        $table->string('sheet_name')->nullable();
        $table->unsignedInteger('remote_row_number')->nullable();
        $table->string('status', 60)->default('pending');
        $table->json('metadata')->nullable();

        $table->index(['sales_lead_id', 'status'], "{$key}_lead_status_index");
        $table->unique(['branch_id', 'operation_uuid'], "{$key}_operation_unique");
        $table->unique(['branch_id', 'oasis_sync_id'], "{$key}_sync_unique");
        $table->unique(['branch_id', 'sheet_name', 'remote_row_number'], "{$key}_remote_row_unique");
    }
};
