<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_lead_consumer_links', function (Blueprint $table): void {
            $table->foreignId('consumer_application_id')->nullable()->after('sales_lead_id')->constrained('consumer_applications')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_lead_consumer_links', function (Blueprint $table): void {
            $table->dropForeign(['consumer_application_id']);
            $table->dropColumn('consumer_application_id');
        });
    }
};
