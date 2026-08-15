<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_agenda_evidence_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('archive_name')->nullable();
            $table->string('storage_path')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('manifest_checksum', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('file_count')->default(0);
            $table->json('manifest')->nullable();
            $table->string('status')->default('building');
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('built_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'week_start']);
        });
        Schema::create('sales_agenda_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('storage_path')->nullable();
            $table->string('file_path')->nullable();
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('checksum', 64)->nullable();
            $table->foreignId('archive_id')->nullable()->constrained('sales_agenda_evidence_archives')->nullOnDelete();
            $table->string('archive_entry_path')->nullable();
            $table->string('archive_status')->default('local_only');
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->index(['content_item_id', 'purged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_agenda_evidences');
        Schema::dropIfExists('sales_agenda_evidence_archives');
    }
};
