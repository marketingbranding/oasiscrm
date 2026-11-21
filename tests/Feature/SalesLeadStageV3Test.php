<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesLeadStageV3Test extends TestCase
{
    use RefreshDatabase;

    public function test_br10_feature_flag_allows_forward_stage_jump_and_audits_transition(): void
    {
        [$lead, $sales] = $this->context(['lead_stage' => 'lead_baru']);
        $this->enableFeature();

        $this->actingAs($sales)->patchJson(route('sales-leads.stage.update', $lead), [
            'stage' => 'survey',
            'action' => 'set',
            'expected_updated_at' => $lead->updated_at->toISOString(),
            'operation_uuid' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('lead_stage', 'survey');

        $this->assertDatabaseHas('sales_leads', ['id' => $lead->id, 'lead_stage' => 'survey']);
        $this->assertDatabaseHas('sales_lead_stage_histories', [
            'sales_lead_id' => $lead->id,
            'from_stage' => 'lead_baru',
            'to_stage' => 'survey',
            'source' => 'web',
        ]);
    }

    public function test_br10_normal_reverse_requires_audited_correction_reason(): void
    {
        [$lead, $sales] = $this->context(['lead_stage' => 'survey']);
        $this->enableFeature();

        $this->actingAs($sales)->patchJson(route('sales-leads.stage.update', $lead), [
            'stage' => 'dihubungi',
            'action' => 'set',
            'expected_updated_at' => $lead->updated_at->toISOString(),
        ])->assertUnprocessable();

        $this->assertSame('survey', $lead->fresh()->lead_stage);

        $response = $this->actingAs($sales)->patchJson(route('sales-leads.stage.update', $lead), [
            'stage' => 'dihubungi',
            'action' => 'reverse',
            'reason' => 'Koreksi input tahap',
            'reversal_confirmed' => true,
            'expected_updated_at' => $lead->fresh()->updated_at->toISOString(),
            'operation_uuid' => (string) Str::uuid(),
        ])->assertOk();

        $response->assertJsonPath('lead_stage', 'dihubungi');
        $this->assertDatabaseHas('sales_lead_stage_histories', [
            'sales_lead_id' => $lead->id,
            'from_stage' => 'survey',
            'to_stage' => 'dihubungi',
            'source' => 'correction',
            'reason' => 'Koreksi input tahap',
        ]);
    }

    public function test_br11_berkas_and_akad_are_not_accepted_as_v3_stages(): void
    {
        [$lead, $sales] = $this->context(['lead_stage' => 'lead_baru']);
        $this->enableFeature();

        $this->actingAs($sales)->patchJson(route('sales-leads.stage.update', $lead), [
            'stage' => 'documents_completed_at',
            'action' => 'set',
            'expected_updated_at' => $lead->updated_at->toISOString(),
        ])->assertUnprocessable();

        $this->assertSame('lead_baru', $lead->fresh()->lead_stage);
    }

    private function enableFeature(): void
    {
        AppSetting::create([
            'key' => 'lead_stages_v3',
            'value' => true,
            'group' => 'feature_flags',
        ]);
    }

    /** @return array{0: SalesLead, 1: User} */
    private function context(array $overrides = []): array
    {
        $branch = Branch::create(['name' => 'Branch '.Str::random(8), 'code' => strtoupper(Str::random(6)), 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Project '.Str::random(8)]);
        $sales = User::factory()->create([
            'branch_id' => $branch->id,
            'role_id' => Role::query()->where('slug', 'manager')->value('id'),
            'password_changed_at' => now(),
        ]);
        $lead = SalesLead::create(array_merge([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-11-21',
            'customer_name' => 'Stage Lead',
            'lead_stage' => 'lead_baru',
        ], $overrides));

        return [$lead, $sales];
    }
}
