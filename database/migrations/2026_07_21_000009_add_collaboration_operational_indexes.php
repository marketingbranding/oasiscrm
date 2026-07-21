<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_presences', function (Blueprint $table) {
            $table->index(['branch_id', 'page_key', 'last_seen_at'], 'presence_page_freshness_index');
            $table->index(['record_type', 'record_id', 'last_seen_at', 'user_id'], 'presence_record_freshness_index');
        });

        Schema::table('user_notifications', function (Blueprint $table) {
            $table->index(['read_at', 'id'], 'notifications_retention_index');
            $table->index(['user_id', 'created_at'], 'notifications_latest_index');
        });
    }

    public function down(): void
    {
        Schema::table('user_presences', function (Blueprint $table) {
            $table->dropIndex('presence_page_freshness_index');
            $table->dropIndex('presence_record_freshness_index');
        });

        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_retention_index');
            $table->dropIndex('notifications_latest_index');
        });
    }
};
