<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesLeadStageBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_br10_stage_backfill_dry_run_reports_counts_and_review_cases_without_writing(): void
    {
        $branch = Branch::query()->create([
            'name' => 'Dry Run Branch',
            'code' => strtoupper(Str::random(6)),
            'is_active' => true,
        ]);
        $project = LeadMaster::query()->create([
            'branch_id' => $branch->id,
            'project_name' => 'Dry Run Project',
            'is_active' => true,
        ]);
        $sales = User::factory()->create(['branch_id' => $branch->id]);
        $attributes = [
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-11-21',
        ];
        $contacted = SalesLead::query()->create($attributes + [
            'customer_name' => 'Contacted',
            'contacted_at' => '2026-11-21 08:00:00',
        ]);
        $akadWithoutUtj = SalesLead::query()->create($attributes + [
            'customer_name' => 'Akad Without UTJ',
            'akad_at' => '2026-11-21 08:00:00',
        ]);
        $withoutStage = SalesLead::query()->create($attributes + ['customer_name' => 'Without Stage']);
        $this->assertDatabaseCount('sales_leads', 3);
        $this->assertNotNull($akadWithoutUtj->fresh()->akad_at);
        $this->assertNull($withoutStage->fresh()->lead_stage);

        $this->artisan('sales-lead:stage-v3-backfill', ['--dry-run' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('dihubungi')
            ->expectsOutputToContain('akad_without_utj')
            ->expectsOutputToContain('without_stage');

        $this->assertNull($contacted->fresh()->lead_stage);
        $this->assertNull($akadWithoutUtj->fresh()->lead_stage);
        $this->assertNull($withoutStage->fresh()->lead_stage);
    }
}
