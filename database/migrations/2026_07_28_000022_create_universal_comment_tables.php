<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->morphs('commentable');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->restrictOnDelete();
            $table->text('body');
            $table->text('body_plain')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index('user_id');
            $table->index('parent_id');
            $table->index('created_at');
            $table->index('deleted_at');
        });

        Schema::create('comment_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mentioned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['comment_id', 'mentioned_user_id']);
            $table->index('mentioned_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_mentions');
        Schema::dropIfExists('comments');
    }
};
