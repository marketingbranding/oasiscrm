<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_master', function (Blueprint $table) {
            $table->boolean('is_nup_eligible')->default(false);
        });

        Schema::table('sales_leads', function (Blueprint $table) {
            $table->string('external_lead_id')->nullable();
            $table->string('id_promo')->nullable();
            $table->string('source')->nullable();
            $table->string('platform')->nullable();
            $table->string('campaign_id')->nullable();
            $table->string('campaign_name')->nullable();
            $table->string('current_status', 40)->default('no_response')->index();
            $table->timestamp('current_status_changed_at')->nullable();
            $table->string('current_status_source', 60)->nullable();
            $table->string('current_status_source_id')->nullable();
            $table->timestamp('consumer_converted_at')->nullable();
            $table->timestamp('freelance_converted_at')->nullable();
            $table->string('consumer_external_id')->nullable();
            $table->string('freelance_external_id')->nullable();
            $table->string('slik_external_id')->nullable();
            $table->string('akad_external_id')->nullable();

            $table->unique(['branch_id', 'external_lead_id'], 'sales_leads_branch_external_unique');
            $table->index(['branch_id', 'current_status'], 'sales_leads_branch_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->dropUnique('sales_leads_branch_external_unique');
            $table->dropIndex('sales_leads_branch_status_index');
            $table->dropIndex(['current_status']);
            $table->dropColumn([
                'external_lead_id',
                'id_promo',
                'source',
                'platform',
                'campaign_id',
                'campaign_name',
                'current_status',
                'current_status_changed_at',
                'current_status_source',
                'current_status_source_id',
                'consumer_converted_at',
                'freelance_converted_at',
                'consumer_external_id',
                'freelance_external_id',
                'slik_external_id',
                'akad_external_id',
            ]);
        });

        Schema::table('lead_master', function (Blueprint $table) {
            $table->dropColumn('is_nup_eligible');
        });
    }
};
