<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->string('sync_status', 20)->default('pending_create')->after('external_sync_id');
            $table->timestamp('last_synced_at')->nullable()->after('sync_status');
            $table->text('last_sync_error')->nullable()->after('last_synced_at');
            $table->index(['branch_id', 'sync_status'], 'sales_leads_branch_sync_status_index');
        });

        DB::table('sales_leads')->whereNotNull('external_sync_id')->update([
            'sync_status' => 'synced',
            'last_synced_at' => DB::raw('updated_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->dropIndex('sales_leads_branch_sync_status_index');
            $table->dropColumn(['sync_status', 'last_synced_at', 'last_sync_error']);
        });
    }
};
