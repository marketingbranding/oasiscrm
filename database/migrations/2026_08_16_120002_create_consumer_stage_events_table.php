<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_stage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_application_id')->constrained('consumer_applications')->cascadeOnDelete();
            $table->string('stage', 40);
            $table->string('status', 40)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 40)->nullable();
            $table->string('source_id')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['consumer_application_id', 'stage', 'occurred_at'], 'consumer_stage_events_application_stage_time_index');
            $table->index(['stage', 'status', 'occurred_at'], 'consumer_stage_events_stage_status_time_index');
            $table->index(['source', 'source_id'], 'consumer_stage_events_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_stage_events');
    }
};
