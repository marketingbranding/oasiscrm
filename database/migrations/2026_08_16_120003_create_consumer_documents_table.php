<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_application_id')->constrained('consumer_applications')->cascadeOnDelete();
            $table->string('document_type', 80);
            $table->string('status', 40)->default('pending');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['consumer_application_id', 'document_type'], 'consumer_documents_application_type_index');
            $table->index(['status', 'verified_at'], 'consumer_documents_status_verified_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_documents');
    }
};
