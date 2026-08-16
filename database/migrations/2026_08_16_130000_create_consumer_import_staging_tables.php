<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('project_id')->constrained('lead_master')->restrictOnDelete();
            $table->string('source', 50);
            $table->string('status', 30);
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedSmallInteger('total_rows')->default(0);
            $table->unsignedSmallInteger('parsed_rows')->default(0);
            $table->unsignedSmallInteger('ready_rows')->default(0);
            $table->unsignedSmallInteger('already_imported_rows')->default(0);
            $table->unsignedSmallInteger('warning_rows')->default(0);
            $table->unsignedSmallInteger('review_rows')->default(0);
            $table->unsignedSmallInteger('invalid_rows')->default(0);
            $table->unsignedSmallInteger('created_customers')->default(0);
            $table->unsignedSmallInteger('created_applications')->default(0);
            $table->unsignedSmallInteger('reused_rows')->default(0);
            $table->unsignedSmallInteger('skipped_rows')->default(0);
            $table->timestamps();
        });

        Schema::create('consumer_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('consumer_import_batches')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->json('normalized_data');
            $table->string('status', 30);
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'line_number'], 'consumer_import_rows_batch_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_import_rows');
        Schema::dropIfExists('consumer_import_batches');
    }
};
