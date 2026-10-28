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
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('current_status_source_id');
            $table->foreignId('completed_by_user_id')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->index(['sales_user_id', 'completed_at'], 'sales_leads_sales_user_completed_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->dropIndex('sales_leads_sales_user_completed_at_index');
            $table->dropConstrainedForeignId('completed_by_user_id');
            $table->dropColumn('completed_at');
        });
    }
};
