<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->string('external_sync_id')->nullable()->after('external_lead_id');
            $table->unique(['branch_id', 'external_sync_id'], 'sales_leads_branch_sync_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->dropUnique('sales_leads_branch_sync_unique');
            $table->dropColumn('external_sync_id');
        });
    }
};
