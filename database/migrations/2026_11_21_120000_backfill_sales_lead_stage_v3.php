<?php

use App\Enums\SalesLeadStatus;
use App\Models\SalesLead;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sales_leads')
            ->whereNull('lead_stage')
            ->orderBy('id')
            ->chunkById(500, function ($leads): void {
                foreach ($leads as $lead) {
                    $status = SalesLeadStatus::tryFrom((string) $lead->current_status);
                    $stage = SalesLead::stageV3FromLegacy($status);
                    if ($lead->contacted_at !== null) {
                        $stage = 'dihubungi';
                    }
                    if ($lead->met_at !== null) {
                        $stage = 'tatap_muka';
                    }
                    if ($lead->surveyed_at !== null) {
                        $stage = 'survey';
                    }
                    if ($lead->utj_at !== null || $stage === 'utj') {
                        $stage = 'utj';
                    }
                    if ($lead->gugur_at !== null) {
                        $stage = 'gugur';
                    }

                    DB::table('sales_leads')->where('id', $lead->id)->update(['lead_stage' => $stage]);
                }
            });
    }

    public function down(): void
    {
        // Backfill is intentionally irreversible; later stage edits must survive rollback.
    }
};
