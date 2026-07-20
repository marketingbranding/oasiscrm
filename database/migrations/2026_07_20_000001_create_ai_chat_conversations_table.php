<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->json('messages');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
            $table->index(['branch_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_conversations');
    }
};
