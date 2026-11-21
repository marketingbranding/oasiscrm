<?php

namespace Tests\Feature;

use App\Enums\SalesLeadStatus;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\SalesLeadStageHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesLeadStageV3MigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_br10_backfill_maps_existing_lead_without_creating_fake_history(): void
    {
        [$newLead, $contactedLead, $surveyLead, $utjLead, $downstreamLead] = $this->leads();

        $migration = require database_path('migrations/2026_11_21_120000_backfill_sales_lead_stage_v3.php');
        $migration->up();

        $this->assertSame('lead_baru', $newLead->fresh()->lead_stage);
        $this->assertSame('dihubungi', $contactedLead->fresh()->lead_stage);
        $this->assertSame('survey', $surveyLead->fresh()->lead_stage);
        $this->assertSame('utj', $utjLead->fresh()->lead_stage);
        $this->assertSame('utj', $downstreamLead->fresh()->lead_stage);
        $this->assertSame(0, SalesLeadStageHistory::query()->count());
    }

    /** @return list<SalesLead> */
    private function leads(): array
    {
        $branch = Branch::create(['name' => 'Branch '.Str::random(8), 'code' => strtoupper(Str::random(6)), 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Project '.Str::random(8)]);
        $sales = User::factory()->create(['branch_id' => $branch->id]);

        $make = fn (array $attributes = []): SalesLead => SalesLead::create(array_merge([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-11-21',
            'customer_name' => 'Lead '.Str::random(5),
            'current_status' => SalesLeadStatus::NoResponse,
        ], $attributes));

        return [
            $make(),
            $make(['contacted_at' => '2026-11-21 08:00:00']),
            $make(['surveyed_at' => '2026-11-21 08:00:00']),
            $make(['utj_at' => '2026-11-21 08:00:00']),
            $make(['current_status' => SalesLeadStatus::Akad]),
        ];
    }
}
