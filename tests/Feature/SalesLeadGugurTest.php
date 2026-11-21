<?php

namespace Tests\Feature;

use App\Enums\SalesLeadStatus;
use App\Models\AppSetting;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\SalesLeadStageHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesLeadGugurTest extends TestCase
{
    use RefreshDatabase;

    public function test_br13_configured_reason_closes_lead_as_gugur_and_is_idempotent(): void
    {
        [$lead, $manager] = $this->context();
        $this->enableFeature();
        $uuid = (string) Str::uuid();

        $this->assertTrue($manager->can('updateLifecycleStatus', $lead));
        $payload = ['status' => 'gugur', 'reason' => 'Tidak berminat', 'operation_uuid' => $uuid];
        $this->actingAs($manager)->patchJson(route('sales-leads.lifecycle-status.update', $lead), $payload)
            ->assertOk()->assertJsonPath('stage', 'gugur');
        $this->actingAs($manager)->patchJson(route('sales-leads.lifecycle-status.update', $lead), $payload)
            ->assertOk();

        $this->assertSame('gugur', $lead->fresh()->lead_stage);
        $this->assertSame('Tidak berminat', $lead->fresh()->gugur_reason);
        $this->assertSame(1, SalesLeadStageHistory::query()->where('operation_uuid', $uuid)->count());
    }

    public function test_br13_unknown_or_missing_reason_does_not_mutate_lead(): void
    {
        [$lead, $manager] = $this->context();
        $this->enableFeature();

        $this->actingAs($manager)->patchJson(route('sales-leads.lifecycle-status.update', $lead), [
            'status' => 'gugur',
            'reason' => 'Alasan palsu',
        ])->assertUnprocessable();
        $this->actingAs($manager)->patchJson(route('sales-leads.lifecycle-status.update', $lead), [
            'status' => 'gugur',
        ])->assertUnprocessable();

        $this->assertSame('lead_baru', $lead->fresh()->lead_stage);
        $this->assertNull($lead->fresh()->gugur_reason);
    }

    public function test_br13_lead_after_utj_cannot_be_closed_as_gugur(): void
    {
        [$lead, $manager] = $this->context(['lead_stage' => 'utj']);
        $this->enableFeature();

        $this->actingAs($manager)->patchJson(route('sales-leads.lifecycle-status.update', $lead), [
            'status' => 'gugur',
            'reason' => 'Tidak berminat',
        ])->assertUnprocessable();

        $this->assertSame('utj', $lead->fresh()->lead_stage);
    }

    public function test_br13_closing_as_gugur_preserves_latest_current_status(): void
    {
        [$lead, $manager] = $this->context([
            'lead_stage' => 'survey',
            'current_status' => SalesLeadStatus::SiteVisit,
        ]);
        $this->enableFeature();

        $this->actingAs($manager)->patchJson(route('sales-leads.lifecycle-status.update', $lead), [
            'status' => 'gugur',
            'reason' => 'Tidak berminat',
        ])->assertOk();

        $this->assertSame('gugur', $lead->fresh()->lead_stage);
        $this->assertSame(SalesLeadStatus::SiteVisit, $lead->fresh()->current_status);
    }

    private function enableFeature(): void
    {
        AppSetting::create(['key' => 'lead_stages_v3', 'value' => true, 'group' => 'feature_flags']);
    }

    /** @return array{0: SalesLead, 1: User} */
    private function context(array $overrides = []): array
    {
        $branch = Branch::create(['name' => 'Branch '.Str::random(8), 'code' => strtoupper(Str::random(6)), 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Project '.Str::random(8)]);
        $manager = User::factory()->create([
            'branch_id' => $branch->id,
            'role_id' => Role::query()->where('slug', 'manager')->value('id'),
            'password_changed_at' => now(),
        ]);
        $lead = SalesLead::create(array_merge([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $manager->id,
            'lead_date' => '2026-11-21',
            'customer_name' => 'Gugur Lead',
            'lead_stage' => 'lead_baru',
        ], $overrides));

        return [$lead, $manager];
    }
}
