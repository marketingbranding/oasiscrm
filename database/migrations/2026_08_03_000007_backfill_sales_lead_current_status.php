<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfill('surveyed_at', 'site_visit');
        $this->backfill('utj_at', 'utj');
        $this->backfill('akad_at', 'akad');
    }

    public function down(): void
    {
        DB::table('sales_lead_status_histories')->where('source', 'legacy_timestamp')->delete();

        DB::table('sales_leads')->update([
            'current_status' => 'no_response',
            'current_status_changed_at' => null,
            'current_status_source' => null,
            'current_status_source_id' => null,
        ]);
    }

    private function backfill(string $timestampColumn, string $status): void
    {
        DB::table('sales_leads')
            ->whereNotNull($timestampColumn)
            ->orderBy('id')
            ->eachById(function ($lead) use ($timestampColumn, $status): void {
                DB::table('sales_leads')->where('id', $lead->id)->update([
                    'current_status' => $status,
                    'current_status_changed_at' => $lead->{$timestampColumn},
                    'current_status_source' => 'legacy_timestamp',
                    'current_status_source_id' => $timestampColumn,
                ]);

                DB::table('sales_lead_status_histories')->updateOrInsert([
                    'sales_lead_id' => $lead->id,
                    'source' => 'legacy_timestamp',
                    'source_id' => $timestampColumn,
                    'status' => $status,
                ], [
                    'branch_id' => $lead->branch_id,
                    'actor_id' => null,
                    'operation_uuid' => null,
                    'changed_at' => $lead->{$timestampColumn},
                    'metadata' => json_encode(['legacy_field' => $timestampColumn], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }
};
