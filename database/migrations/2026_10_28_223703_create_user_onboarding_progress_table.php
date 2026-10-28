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
        Schema::create('user_onboarding_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('flow_key', 100);
            $table->string('version', 50);
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'flow_key', 'version'], 'user_onboarding_progress_identity_unique');
            $table->index(['user_id', 'flow_key'], 'user_onboarding_progress_user_flow_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_onboarding_progress');
    }
};
