<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('consumer_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_application_id')->constrained('consumer_applications')->cascadeOnDelete();
            $table->string('process_key', 40);
            $table->string('category', 50)->nullable();
            $table->text('description');
            $table->dateTime('opened_at')->nullable();
            $table->foreignId('pic_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('open');
            $table->text('resolution')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 50)->nullable();
            $table->string('source_id', 150)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['consumer_application_id', 'status']);
            $table->index(['process_key', 'status']);
            $table->index(['source', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consumer_issues');
    }
};
