<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumer_applications', function (Blueprint $table): void {
            $table->string('consumer_status')->nullable()->after('application_status');
            $table->string('source_last_process')->nullable()->after('current_stage');
            $table->string('source_completeness_status')->nullable()->after('source_last_process');
        });
    }

    public function down(): void
    {
        Schema::table('consumer_applications', function (Blueprint $table): void {
            $table->dropColumn(['consumer_status', 'source_last_process', 'source_completeness_status']);
        });
    }
};
