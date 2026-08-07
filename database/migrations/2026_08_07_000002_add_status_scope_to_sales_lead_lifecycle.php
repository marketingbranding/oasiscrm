<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_lead_lifecycle_sync_statuses', 'scope')) {
            Schema::table('sales_lead_lifecycle_sync_statuses', function (Blueprint $table) {
                $table->string('scope', 40)->nullable()->default('lead')->after('branch_id');
            });
        }

        DB::table('sales_lead_lifecycle_sync_statuses')->whereNull('scope')->update(['scope' => 'lead']);

        Schema::table('sales_lead_lifecycle_sync_statuses', function (Blueprint $table) {
            if ($this->hasUniqueBranchScope()) {
                return;
            }
            $table->dropUnique(['branch_id']);
            $table->unique(['branch_id', 'scope'], 'lead_lifecycle_status_branch_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sales_lead_lifecycle_sync_statuses', function (Blueprint $table) {
            $table->dropUnique('lead_lifecycle_status_branch_scope_unique');
            $table->unique(['branch_id']);
        });
        Schema::table('sales_lead_lifecycle_sync_statuses', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }

    private function hasUniqueBranchScope(): bool
    {
        $indexes = Schema::connection(null)->getIndexes('sales_lead_lifecycle_sync_statuses');

        return array_key_exists('lead_lifecycle_status_branch_scope_unique', $indexes);
    }
};
