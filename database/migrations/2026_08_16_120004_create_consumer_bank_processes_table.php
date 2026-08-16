<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_bank_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_application_id')->constrained('consumer_applications')->cascadeOnDelete();
            $table->string('bank_name')->nullable();
            $table->string('status', 40)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('sp3k_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('source', 40)->nullable();
            $table->timestamps();

            $table->index(['consumer_application_id', 'status'], 'consumer_bank_processes_application_status_index');
            $table->index(['status', 'submitted_at'], 'consumer_bank_processes_status_submitted_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_bank_processes');
    }
};
