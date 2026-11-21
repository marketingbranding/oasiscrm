<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\SalesLeadSpreadsheetContract;
use App\ValueObjects\ResolvedSalesLeadSpreadsheetContract;
use App\ValueObjects\SalesLeadSheetDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesLeadStageSpreadsheetCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_br10_and_br13_contract_accepts_canonical_utj_and_gugur_status_values(): void
    {
        $contracts = app(SalesLeadSpreadsheetContract::class);
        $definition = $contracts->definitions()['lead'];

        $this->assertContains('UTJ', $definition->validations['status_lead']['values']);
        $this->assertContains('Gugur', $definition->validations['status_lead']['values']);
    }

    public function test_br11_transaction_fact_is_not_derived_from_lead_stage(): void
    {
        $lead = $this->lead('utj');
        $lead->update(['akad_at' => '2026-11-21 08:00:00']);

        $this->assertSame('utj', $lead->fresh()->resolveStageV3());
        $this->assertNotNull($lead->fresh()->akad_at);
    }

    public function test_br10_contract_maps_v3_labels_to_existing_sheet_dropdown_labels(): void
    {
        $lead = $this->lead('dihubungi');
        $contracts = app(SalesLeadSpreadsheetContract::class);

        $this->assertSame('Diskusi', $contracts->statusForLead($lead));
        $lead->update(['lead_stage' => 'survey']);
        $this->assertSame('Cek Lokasi', $contracts->statusForLead($lead));
    }

    public function test_br13_gugur_status_returns_null_when_branch_contract_lacks_option(): void
    {
        $lead = $this->lead('gugur');
        $contract = new ResolvedSalesLeadSpreadsheetContract('sheet', $this->definition(), 1, [], [], [], 2, ['status_lead' => ['UTJ']]);

        $this->assertNull(app(SalesLeadSpreadsheetContract::class)->statusForLead($lead, $contract));
    }

    public function test_br10_and_br13_canonical_stage_maps_to_sheet_status_without_changing_identity(): void
    {
        $lead = $this->lead('utj');
        $lead->update(['external_sync_id' => 'stable-sync-id']);

        $this->assertSame('UTJ', app(SalesLeadSpreadsheetContract::class)->statusForLead($lead));
        $this->assertSame('stable-sync-id', $lead->fresh()->external_sync_id);

        $lead->update(['lead_stage' => 'gugur']);
        $this->assertSame('Gugur', app(SalesLeadSpreadsheetContract::class)->statusForLead($lead));
        $this->assertSame('stable-sync-id', $lead->fresh()->external_sync_id);
    }

    private function definition(): SalesLeadSheetDefinition
    {
        return app(SalesLeadSpreadsheetContract::class)->definitions()['lead'];
    }

    private function lead(string $stage): SalesLead
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
            'role_id' => Role::query()->where('slug', 'manager')->value('id'),
            'password_changed_at' => now(),
        ]);

        return SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-11-21',
            'customer_name' => 'Sync Lead',
            'lead_stage' => $stage,
        ]);
    }
}
