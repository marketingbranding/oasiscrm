<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dana_talangans', function (Blueprint $table) {
            $table->uuid('oasis_sync_id')->nullable()->unique()->after('id');
            $table->string('sheet_name')->nullable()->after('oasis_sync_id');
            $table->unsignedInteger('sheet_row_number')->nullable()->after('sheet_name');
            $table->string('sync_status')->default('pending')->after('sheet_row_number');
            $table->text('last_sync_error')->nullable()->after('sync_status');
            $table->string('source_hash', 64)->nullable()->after('last_sync_error');
            $table->timestamp('last_synced_at')->nullable()->after('source_hash');
            $table->softDeletes();

            $table->index(['sheet_name', 'sheet_row_number']);
            $table->index('sync_status');
        });

        Schema::create('dana_talangan_sync_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('spreadsheet_id')->unique();
            $table->string('status')->default('never');
            $table->text('message')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dana_talangan_sync_statuses');

        Schema::table('dana_talangans', function (Blueprint $table) {
            $table->dropIndex(['sheet_name', 'sheet_row_number']);
            $table->dropIndex(['sync_status']);
            $table->dropUnique(['oasis_sync_id']);
            $table->dropColumn([
                'oasis_sync_id',
                'sheet_name',
                'sheet_row_number',
                'sync_status',
                'last_sync_error',
                'source_hash',
                'last_synced_at',
                'deleted_at',
            ]);
        });
    }
};
