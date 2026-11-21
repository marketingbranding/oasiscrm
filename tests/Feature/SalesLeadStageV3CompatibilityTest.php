<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesLeadStageV3CompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_br10_and_br14_flag_off_keeps_legacy_status_form_and_json_behavior(): void
    {
        [$lead, $sales] = $this->context('discussion');

        $this->actingAs($sales)->patchJson(route('sales-leads.lifecycle-status.update', $lead), [
            'status' => 'face_to_face',
        ])->assertOk();

        $this->assertSame('face_to_face', $lead->fresh()->current_status->value);
        $this->assertNull($lead->fresh()->lead_stage);
    }

    private function context(string $status): array
    {
        $branch = Branch::query()->create(['name' => 'Legacy Branch', 'code' => strtoupper(Str::random(6)), 'is_active' => true]);
        $project = LeadMaster::query()->create(['branch_id' => $branch->id, 'project_name' => 'Legacy Project', 'is_active' => true]);
        $sales = User::factory()->create([
            'branch_id' => $branch->id,
            'role_id' => Role::query()->where('slug', 'sales')->value('id'),
            'password_changed_at' => now(),
        ]);
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);
        $lead = SalesLead::query()->create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-11-21',
            'customer_name' => 'Legacy Lead',
            'current_status' => $status,
        ]);

        return [$lead, $sales];
    }
}
