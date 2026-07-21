<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['database_sheet_sync_statuses', 'konsumen_progress_sync_statuses', 'dana_talangan_sync_statuses'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('last_successful_at')->nullable();
                $table->unsignedBigInteger('duration_ms')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['database_sheet_sync_statuses', 'konsumen_progress_sync_statuses', 'dana_talangan_sync_statuses'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('initiated_by');
                $table->dropColumn(['last_successful_at', 'duration_ms']);
            });
        }
    }
};
