<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesLeadStageAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_br14_sales_can_advance_owned_lead_until_utj(): void
    {
        [$lead, $sales] = $this->context('survey');
        $this->enableFeature();

        $this->actingAs($sales)->patchJson(route('sales-leads.stage.update', $lead), [
            'stage' => 'utj',
            'action' => 'set',
            'expected_updated_at' => $lead->updated_at->toISOString(),
        ])->assertOk()->assertJsonPath('lead_stage', 'utj');
    }

    public function test_br14_sales_cannot_edit_owned_lead_after_utj(): void
    {
        [$lead, $sales] = $this->context('utj');
        $this->enableFeature();

        $this->actingAs($sales)->patchJson(route('sales-leads.stage.update', $lead), [
            'stage' => 'utj',
            'action' => 'set',
            'expected_updated_at' => $lead->updated_at->toISOString(),
        ])->assertForbidden();
    }

    public function test_br14_supplemental_role_does_not_unlock_sales_after_utj(): void
    {
        [$lead, $sales] = $this->context('utj');
        $managerRole = Role::query()->where('slug', 'manager')->firstOrFail();
        $sales->roles()->attach($managerRole->id);
        $this->enableFeature();

        $this->actingAs($sales)->patchJson(route('sales-leads.stage.update', $lead), [
            'stage' => 'utj',
            'action' => 'set',
            'expected_updated_at' => $lead->updated_at->toISOString(),
        ])->assertForbidden();
    }

    public function test_br14_v3_rejects_legacy_manual_status_mutation_for_manager(): void
    {
        [$lead, $manager] = $this->context('survey');
        $manager->update(['role_id' => Role::query()->where('slug', 'manager')->value('id')]);
        $this->enableFeature();

        $this->actingAs($manager)->patchJson(route('sales-leads.lifecycle-status.update', $lead), [
            'status' => 'discussion',
            'expected_updated_at' => $lead->updated_at->toISOString(),
        ])->assertUnprocessable();
    }

    public function test_br14_sales_cannot_reopen_utj_via_lifecycle_json_or_sheet_pull(): void
    {
        [$lead, $sales] = $this->context('utj');
        $this->enableFeature();

        $this->actingAs($sales)->patchJson(route('sales-leads.lifecycle-status.update', $lead), [
            'status' => 'discussion',
            'expected_updated_at' => $lead->updated_at->toISOString(),
        ])->assertForbidden();

        $this->assertSame('utj', $lead->fresh()->lead_stage);
    }

    public function test_br14_sales_cannot_use_direct_url_for_out_of_scope_lead(): void
    {
        [$ownedLead, $sales] = $this->context('survey');
        [$outsideLead] = $this->context('survey', false);
        $this->enableFeature();

        $this->actingAs($sales)->patchJson(route('sales-leads.stage.update', $outsideLead), [
            'stage' => 'utj',
            'action' => 'set',
            'expected_updated_at' => $outsideLead->updated_at->toISOString(),
        ])->assertForbidden();

        $this->assertSame('survey', $ownedLead->fresh()->lead_stage);
        $this->assertSame('survey', $outsideLead->fresh()->lead_stage);
    }

    public function test_br23_pre_utj_conversion_requires_reason_and_allowed_role(): void
    {
        [$lead, $sales] = $this->context('survey');
        $this->enableFeature();
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'admin')->value('id'),
            'branch_id' => $lead->branch_id,
            'password_changed_at' => now(),
        ]);
        $lead->sales_user_id = $admin->id;
        $lead->save();

        $this->actingAs($admin)->postJson(route('sales-leads.consumer.store', $lead), [
            'project_id' => $lead->project_id,
            'nik' => '0123456789012345',
            'id_kavling' => 'A-1',
        ])->assertJsonValidationErrors('pre_utj_exception_reason');
    }

    public function test_br23_sales_cannot_pre_utj_convert_even_with_supplemental_role(): void
    {
        [$lead, $sales] = $this->context('survey');
        $sales->roles()->attach(Role::query()->where('slug', 'admin')->value('id'));
        $this->enableFeature();

        $this->actingAs($sales)->postJson(route('sales-leads.consumer.store', $lead), [
            'project_id' => $lead->project_id,
            'nik' => '0123456789012345',
            'id_kavling' => 'A-1',
            'pre_utj_exception_reason' => 'Pengecualian uji BR-23',
        ])->assertForbidden();
    }

    public function test_br23_pre_utj_conversion_records_reason_in_audit_for_coordinator(): void
    {
        [$lead, $sales] = $this->context('survey');
        $this->enableFeature();
        $coordinator = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'sales_coordinator')->value('id'),
            'branch_id' => $lead->branch_id,
            'password_changed_at' => now(),
        ]);
        DB::table('sales_coordinator_sales')->insert([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($coordinator)->postJson(route('sales-leads.consumer.store', $lead), [
            'project_id' => $lead->project_id,
            'nik' => '0123456789012345',
            'id_kavling' => 'A-1',
            'pre_utj_exception_reason' => 'Pengecualian uji BR-23',
        ])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => SalesLead::class,
            'subject_id' => $lead->id,
            'event' => 'consumer_converted_before_utj',
        ]);
        $this->assertSame('Pengecualian uji BR-23', DB::table('activity_log')->where('subject_type', SalesLead::class)->where('subject_id', $lead->id)->where('event', 'consumer_converted_before_utj')->value('properties->reason'));
    }

    private function enableFeature(): void
    {
        AppSetting::create(['key' => 'lead_stages_v3', 'value' => true, 'group' => 'feature_flags']);
    }

    /** @return array{0: SalesLead, 1: User} */
    private function context(string $stage, bool $assignSales = true): array
    {
        $branch = Branch::create([
            'name' => 'Branch '.Str::random(8),
            'code' => strtoupper(Str::random(6)),
            'is_active' => true,
        ]);
        $project = LeadMaster::create([
            'branch_id' => $branch->id,
            'project_name' => 'Project '.Str::random(8),
            'is_active' => true,
        ]);
        $sales = User::factory()->create([
            'branch_id' => $branch->id,
            'role_id' => Role::query()->where('slug', 'sales')->value('id'),
            'password_changed_at' => now(),
        ]);
        if ($assignSales) {
            $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);
        }
        $lead = SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-11-21',
            'customer_name' => 'Authorization Lead',
            'lead_stage' => $stage,
        ]);

        return [$lead, $sales];
    }
}
