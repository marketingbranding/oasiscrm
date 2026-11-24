<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');
            Schema::table('consumer_lead_link_decisions', function (Blueprint $table): void {
                $table->foreignId('consumer_application_id')->nullable()->change();
            });
            DB::statement('PRAGMA foreign_keys=ON');

            return;
        }

        Schema::table('consumer_lead_link_decisions', function (Blueprint $table): void {
            $table->foreignId('consumer_application_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Existing Sheet/NUP decisions make restoring NOT NULL unsafe.
    }
};
