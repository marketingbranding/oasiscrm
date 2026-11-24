<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerLeadLinkDecision;
use App\Models\ConsumerLegacyIdentity;
use App\Models\KonsumenProgressSheetRow;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\ConsumerLeadLinkCarryOverService;
use App\Services\ConsumerPasteImportService;
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

        $this->assertSame('already_moved', $result);
        $this->assertDatabaseHas('consumer_lead_link_decisions', ['id' => $decision->id, 'is_active' => false]);
        $this->assertDatabaseHas('consumer_lead_link_decisions', ['subject_key' => 'application:'.$application->id, 'status' => 'legacy', 'is_active' => true]);
        $this->assertDatabaseHas('activity_log', ['event' => 'consumer_lead_link_carry_over', 'subject_id' => $application->id]);
    }

    public function test_br_paste_import_carries_sheet_decision_when_id_kons_arrives_after_application(): void
    {
        $branch = Branch::create(['name' => 'Import Branch', 'code' => 'IBR', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Import Project', 'is_active' => true]);
        $decision = ConsumerLeadLinkDecision::create(['branch_id' => $branch->id, 'legacy_consumer_key' => 'ksm-003', 'subject_type' => 'sheet', 'subject_key' => 'sheet:'.$branch->id.':ksm-003', 'status' => 'legacy', 'decision_source' => 'manual', 'reason' => 'Data lama', 'decided_at' => now(), 'is_active' => true]);
        $admin = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'email_verified_at' => now(), 'password_changed_at' => now()]);
        $admin->roles()->attach(Role::query()->where('slug', 'superadmin')->value('id'));
        $input = "Nama Konsumen\tID Konsumen\nImportir\tKSM-003";
        $batch = app(ConsumerPasteImportService::class)->createBatch($admin, $branch, $project, $input);
        app(ConsumerPasteImportService::class)->import($batch, $admin);

        $this->assertDatabaseHas('consumer_legacy_identities', ['id_kons' => 'KSM-003']);
        $this->assertDatabaseHas('consumer_lead_link_decisions', ['id' => $decision->id, 'is_active' => false]);
        $this->assertDatabaseHas('consumer_lead_link_decisions', ['subject_type' => 'application', 'status' => 'legacy', 'is_active' => true]);
    }

    public function test_br_carry_over_repeat_deactivates_source_and_does_not_duplicate_target(): void
    {
        $application = ConsumerApplication::factory()->create();
        $row = KonsumenProgressSheetRow::create(['branch_id' => $application->branch_id, 'sheet_id' => 'sheet', 'sheet_name' => 'data_konsumen', 'row_hash' => Str::random(8), 'row_data' => ['[SYS] ID Konsumen' => 'KSM-002']]);
        ConsumerLeadLinkDecision::create(['branch_id' => $application->branch_id, 'legacy_consumer_key' => 'ksm-002', 'legacy_sheet_row_id' => $row->id, 'subject_type' => 'sheet', 'subject_key' => 'sheet:'.$application->branch_id.':ksm-002', 'status' => 'legacy', 'decision_source' => 'manual', 'reason' => 'lama', 'decided_at' => now(), 'is_active' => true]);
        ConsumerLegacyIdentity::create(['consumer_application_id' => $application->id, 'legacy_source' => 'sheet', 'id_kons' => 'KSM-002']);
        $service = app(ConsumerLeadLinkCarryOverService::class);

        $this->assertSame('already_moved', $service->carryApplication($application));
        $this->assertSame('already_moved', $service->carryApplication($application->fresh()));
        $this->assertDatabaseCount('consumer_lead_link_decisions', 2);
    }
}
