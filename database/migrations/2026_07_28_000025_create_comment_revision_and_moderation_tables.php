<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('previous_body');
            $table->json('previous_mentioned_user_ids')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['comment_id', 'created_at']);
            $table->index('edited_by');
        });

        Schema::create('comment_moderations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('moderated_by')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['comment_id', 'created_at']);
            $table->index(['moderated_by', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_moderations');
        Schema::dropIfExists('comment_revisions');
    }
};
