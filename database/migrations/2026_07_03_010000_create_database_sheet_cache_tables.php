<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_sheet_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('sheet_id');
            $table->string('sheet_name');
            $table->unsignedInteger('row_number');
            $table->string('oasis_sync_id')->nullable();
            $table->json('headers');
            $table->json('row_data');
            $table->json('formula_columns')->nullable();
            $table->string('sync_status')->default('synced');
            $table->text('last_sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('oasis_deleted_at')->nullable();
            $table->foreignId('oasis_deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'sheet_name']);
            $table->index(['branch_id', 'sync_status']);
            $table->unique(['branch_id', 'sheet_name', 'row_number'], 'dsr_branch_sheet_row_unique');
        });

        Schema::create('database_sheet_sync_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('never');
            $table->text('message')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_sheet_sync_statuses');
        Schema::dropIfExists('database_sheet_records');
    }
};
