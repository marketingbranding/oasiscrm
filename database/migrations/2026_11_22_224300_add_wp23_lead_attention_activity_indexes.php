<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = [
            ['sales_lead_stage_histories', 'wp23_stage_lead_changed_index', ['sales_lead_id', 'changed_at']],
            ['sales_lead_site_visits', 'wp23_site_visit_lead_created_index', ['sales_lead_id', 'created_at']],
            ['sales_lead_transfers', 'wp23_transfer_lead_created_index', ['sales_lead_id', 'created_at']],
            ['comments', 'wp23_comments_target_created_index', ['commentable_type', 'commentable_id', 'created_at']],
        ];

        foreach ($indexes as [$table, $name, $columns]) {
            if (Schema::hasTable($table) && ! Schema::hasIndex($table, $name) && ! Schema::hasIndex($table, $columns)) {
                Schema::table($table, function (Blueprint $table) use ($name, $columns): void {
                    $table->index($columns, $name);
                });
            }
        }

        if (Schema::hasTable('user_notifications') && ! Schema::hasColumn('user_notifications', 'dedupe_key')) {
            Schema::table('user_notifications', function (Blueprint $table): void {
                $table->string('dedupe_key', 160)->nullable()->unique('wp23_attention_notification_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('user_notifications') && Schema::hasColumn('user_notifications', 'dedupe_key')) {
            Schema::table('user_notifications', function (Blueprint $table): void {
                $table->dropUnique('wp23_attention_notification_unique');
                $table->dropColumn('dedupe_key');
            });
        }

        foreach ([
            'sales_lead_stage_histories' => 'wp23_stage_lead_changed_index',
            'sales_lead_site_visits' => 'wp23_site_visit_lead_created_index',
            'sales_lead_transfers' => 'wp23_transfer_lead_created_index',
            'comments' => 'wp23_comments_target_created_index',
        ] as $table => $name) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, function (Blueprint $table) use ($name): void {
                    $table->dropIndex($name);
                });
            }
        }
    }
};
