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
        Schema::create('consumer_process_applicabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_application_id')->constrained('consumer_applications')->cascadeOnDelete();
            $table->string('process_key', 40);
            $table->string('applicability', 25);
            $table->text('reason')->nullable();
            $table->string('source', 50)->nullable();
            $table->string('source_id', 150)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['consumer_application_id', 'process_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consumer_process_applicabilities');
    }
};
