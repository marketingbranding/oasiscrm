<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerLeadLinkDecision;
use App\Models\ConsumerLegacyIdentity;
use App\Models\KonsumenProgressSheetRow;
use App\Models\LeadMaster;
use App\Services\ConsumerLeadLinkCarryOverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsumerLeadLinkCarryOverTest extends TestCase
{
    use RefreshDatabase;

    public function test_br_carry_over_matches_id_kons_sheet_identity_case_insensitively_and_audits_move(): void
    {
        $branch = Branch::create(['name' => 'Carry Branch', 'code' => 'CBR', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Carry Project', 'is_active' => true]);
        $row = KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => 'sheet', 'sheet_name' => 'data_konsumen', 'row_hash' => 'carry', 'row_data' => ['[SYS] ID Konsumen' => 'KSM-001', 'nama_konsumen' => 'Carry']]);
        $decision = ConsumerLeadLinkDecision::create(['branch_id' => $branch->id, 'legacy_consumer_key' => 'ksm-001', 'legacy_sheet_row_id' => $row->id, 'subject_type' => 'sheet', 'subject_key' => 'sheet:'.$branch->id.':ksm-001', 'status' => 'legacy', 'decision_source' => 'manual', 'reason' => 'Data lama', 'decided_at' => now(), 'is_active' => true]);
        $application = ConsumerApplication::factory()->create(['branch_id' => $branch->id, 'project_id' => $project->id, 'nama_konsumen' => 'Carry']);
        ConsumerLegacyIdentity::create(['consumer_application_id' => $application->id, 'legacy_source' => 'sheet', 'id_kons' => ' KSM-001 ']);

        $result = app(ConsumerLeadLinkCarryOverService::class)->carryApplication($application);

        $this->assertSame('moved', $result);
        $this->assertDatabaseHas('consumer_lead_link_decisions', ['id' => $decision->id, 'is_active' => false]);
        $this->assertDatabaseHas('consumer_lead_link_decisions', ['subject_key' => 'application:'.$application->id, 'status' => 'legacy', 'is_active' => true]);
        $this->assertDatabaseHas('activity_log', ['event' => 'consumer_lead_link_carry_over', 'subject_id' => $application->id]);
    }

    public function test_br_carry_over_repeat_deactivates_source_and_does_not_duplicate_target(): void
    {
        $application = ConsumerApplication::factory()->create();
        $row = KonsumenProgressSheetRow::create(['branch_id' => $application->branch_id, 'sheet_id' => 'sheet', 'sheet_name' => 'data_konsumen', 'row_hash' => Str::random(8), 'row_data' => ['[SYS] ID Konsumen' => 'KSM-002']]);
        ConsumerLeadLinkDecision::create(['branch_id' => $application->branch_id, 'legacy_consumer_key' => 'ksm-002', 'legacy_sheet_row_id' => $row->id, 'subject_type' => 'sheet', 'subject_key' => 'sheet:'.$application->branch_id.':ksm-002', 'status' => 'legacy', 'decision_source' => 'manual', 'reason' => 'lama', 'decided_at' => now(), 'is_active' => true]);
        ConsumerLegacyIdentity::create(['consumer_application_id' => $application->id, 'legacy_source' => 'sheet', 'id_kons' => 'KSM-002']);
        $service = app(ConsumerLeadLinkCarryOverService::class);

        $this->assertSame('moved', $service->carryApplication($application));
        $this->assertSame('not_found', $service->carryApplication($application->fresh()));
        $this->assertDatabaseCount('consumer_lead_link_decisions', 2);
    }
}
