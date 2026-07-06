<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('konsumen_progress_sheet_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('sheet_id');
            $table->string('sheet_name');
            $table->string('row_hash', 64);
            $table->json('row_data');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'sheet_name']);
            $table->index(['sheet_id', 'sheet_name']);
            $table->unique(['branch_id', 'sheet_name', 'row_hash'], 'kpsr_branch_sheet_hash_unique');
        });

        Schema::create('konsumen_progress_sync_statuses', function (Blueprint $table) {
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
        Schema::dropIfExists('konsumen_progress_sync_statuses');
        Schema::dropIfExists('konsumen_progress_sheet_rows');
    }
};
