<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_presences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('page_key', 100);
            $table->string('record_type', 50)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->string('context_key', 80)->default('page');
            $table->string('mode', 20)->default('viewing');
            $table->string('session_key', 100);
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['user_id', 'session_key', 'page_key', 'context_key'], 'user_presences_context_unique');
            $table->index('last_seen_at');
            $table->index(['branch_id', 'page_key']);
            $table->index(['record_type', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_presences');
    }
};
