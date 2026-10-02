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
        Schema::create('consumer_nups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('lead_master')->nullOnDelete();
            $table->foreignId('converted_application_id')->nullable()->constrained('consumer_applications')->nullOnDelete();
            $table->string('nup_number', 80)->nullable();
            $table->date('registered_at');
            $table->string('status', 30)->default('waiting');
            $table->string('source', 50)->nullable();
            $table->string('source_id', 150)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->unique(['branch_id', 'nup_number']);
            $table->index(['source', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consumer_nups');
    }
};
