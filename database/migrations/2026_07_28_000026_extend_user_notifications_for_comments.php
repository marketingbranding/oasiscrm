<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->foreignId('comment_id')->nullable()->constrained('comments')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('data')->nullable();

            $table->index('comment_id', 'notifications_comment_index');
            $table->unique(['user_id', 'type', 'comment_id'], 'notifications_comment_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropUnique('notifications_comment_recipient_unique');
            $table->dropIndex('notifications_comment_index');
            $table->dropConstrainedForeignId('comment_id');
            $table->dropConstrainedForeignId('actor_user_id');
            $table->dropColumn('data');
        });
    }
};
