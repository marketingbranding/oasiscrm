<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('consumer_lead_link_decisions', 'consumer_lead_link_decisions_active_unique')) {
            Schema::table('consumer_lead_link_decisions', function (Blueprint $table): void {
                $table->dropIndex('consumer_lead_link_decisions_active_unique');
            });
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');
            Schema::table('consumer_lead_link_decisions', function (Blueprint $table): void {
                $table->foreignId('consumer_application_id')->nullable()->change();
            });
            DB::statement('PRAGMA foreign_keys=ON');
        } else {
            Schema::table('consumer_lead_link_decisions', function (Blueprint $table): void {
                $table->foreignId('consumer_application_id')->nullable()->change();
            });
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            if (! Schema::hasColumn('consumer_lead_link_decisions', 'active_consumer_application_key')) {
                DB::statement('ALTER TABLE consumer_lead_link_decisions ADD active_consumer_application_key BIGINT GENERATED ALWAYS AS (IF(is_active = 1, consumer_application_id, NULL)) STORED');
            }

            if (! Schema::hasColumn('consumer_lead_link_decisions', 'active_subject_key')) {
                DB::statement('ALTER TABLE consumer_lead_link_decisions ADD active_subject_key VARCHAR(255) GENERATED ALWAYS AS (IF(is_active = 1, subject_key, NULL)) STORED');
            }

            if (! Schema::hasIndex('consumer_lead_link_decisions', 'consumer_lead_link_decisions_active_unique')) {
                DB::statement('CREATE UNIQUE INDEX consumer_lead_link_decisions_active_unique ON consumer_lead_link_decisions (active_consumer_application_key)');
            }
        } else {
            DB::statement('ALTER TABLE consumer_lead_link_decisions ADD active_consumer_application_key INTEGER GENERATED ALWAYS AS (CASE WHEN is_active = 1 THEN consumer_application_id ELSE NULL END) STORED');
            DB::statement('CREATE UNIQUE INDEX consumer_lead_link_decisions_active_unique ON consumer_lead_link_decisions (active_consumer_application_key)');
        }
    }

    public function down(): void
    {
        // Existing Sheet/NUP decisions make restoring NOT NULL unsafe.
    }
};
