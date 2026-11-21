<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Menambahkan histori event transaksi bertipe';

    public function up(): void
    {
        if (Schema::hasTable('consumer_stage_events') && ! Schema::hasColumn('consumer_stage_events', 'event_type')) {
            Schema::table('consumer_stage_events', function (Blueprint $table): void {
                $table->string('event_type', 40)->nullable()->after('stage');
            });
        }

        if (Schema::hasTable('consumer_stage_events') && ! Schema::hasIndex('consumer_stage_events', 'consumer_stage_events_application_event_time_index')) {
            Schema::table('consumer_stage_events', function (Blueprint $table): void {
                $table->index(['consumer_application_id', 'event_type', 'occurred_at'], 'consumer_stage_events_application_event_time_index');
            });
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Histori event transaksi kini tersimpan terpisah dari histori tahap proses tanpa mengubah riwayat legacy.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();

        if (! Schema::hasTable('consumer_stage_events')) {
            return;
        }

        Schema::table('consumer_stage_events', function (Blueprint $table): void {
            if (Schema::hasIndex('consumer_stage_events', 'consumer_stage_events_application_event_time_index')) {
                $table->dropIndex('consumer_stage_events_application_event_time_index');
            }
            if (Schema::hasColumn('consumer_stage_events', 'event_type')) {
                $table->dropColumn('event_type');
            }
        });
    }
};
