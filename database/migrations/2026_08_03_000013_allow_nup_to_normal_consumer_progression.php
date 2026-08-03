<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_lead_consumer_links', function (Blueprint $table) {
            $table->dropUnique('lead_consumer_branch_nik_unique');
            $table->unique(['branch_id', 'nik', 'sheet_type'], 'lead_consumer_branch_nik_sheet_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sales_lead_consumer_links', function (Blueprint $table) {
            $table->dropUnique('lead_consumer_branch_nik_sheet_unique');
            $table->unique(['branch_id', 'nik'], 'lead_consumer_branch_nik_unique');
        });
    }
};
