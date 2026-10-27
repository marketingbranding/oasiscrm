<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_onboarding_proposals', function (Blueprint $table): void {
            $table->string('pending_email')->nullable()->after('email');
        });

        DB::table('sales_onboarding_proposals')
            ->where('status', 'pending')
            ->update(['pending_email' => DB::raw('LOWER(email)')]);

        Schema::table('sales_onboarding_proposals', function (Blueprint $table): void {
            $table->unique('pending_email', 'sales_onboarding_proposals_pending_email_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_onboarding_proposals', function (Blueprint $table): void {
            $table->dropUnique('sales_onboarding_proposals_pending_email_unique');
            $table->dropColumn('pending_email');
        });
    }
};
