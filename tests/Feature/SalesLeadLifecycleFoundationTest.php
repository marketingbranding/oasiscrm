<?php

namespace Tests\Feature;

use App\Enums\SalesLeadStatus;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\SalesLeadStatusHistory;
use App\Models\User;
use App\Services\SalesLeadLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesLeadLifecycleFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_codes_alias_manual_ownership_and_precedence_are_canonical(): void
    {
        $service = app(SalesLeadLifecycleService::class);

        $this->assertSame(
            ['no_response', 'discussion', 'site_visit'],
            $service->allowedManualStatuses(),
        );
        $this->assertSame(SalesLeadStatus::SlikCheck, SalesLeadStatus::fromInput('Cek Silk'));
        $this->assertSame(
            SalesLeadStatus::Akad,
            $service->resolvePrimaryStatus([
                'discussion', 'freelance', 'slik_check', 'utj', 'slik_rejected', 'akad', 'site_visit',
            ]),
        );
        $this->assertSame(
            SalesLeadStatus::SiteVisit,
            $service->resolvePrimaryStatus(['freelance', 'site_visit']),
        );
    }

    public function test_manual_status_is_owned_by_service_and_forged_or_downgrade_statuses_are_rejected(): void
    {
        [$lead, $actor] = $this->context();
        $service = app(SalesLeadLifecycleService::class);
        $updated = $service->setManualStatus($lead, 'discussion', $actor);

        $this->assertSame(SalesLeadStatus::Discussion, $updated->current_status);
        $this->assertSame('manual', $updated->current_status_source);
        $this->assertSame((string) $actor->id, $updated->current_status_source_id);
        $this->assertDatabaseHas('sales_lead_status_histories', [
            'sales_lead_id' => $lead->id,
            'branch_id' => $lead->branch_id,
            'actor_id' => $actor->id,
            'status' => 'discussion',
            'source' => 'manual',
        ]);

        try {
            $service->setManualStatus($updated, 'akad', $actor);
            $this->fail('A forged system-owned status was accepted.');
        } catch (\DomainException) {
            $this->assertSame(SalesLeadStatus::Discussion, $updated->fresh()->current_status);
        }

        $updated->update(['current_status' => SalesLeadStatus::Utj, 'current_status_source' => 'consumer']);
        $this->expectException(\DomainException::class);
        $service->setManualStatus($updated->fresh(), 'site_visit', $actor);
    }

    public function test_freelance_conversion_coexists_without_replacing_primary_status(): void
    {
        [$lead, $actor] = $this->context();
        $lead->update([
            'current_status' => SalesLeadStatus::SiteVisit,
            'freelance_converted_at' => now(),
            'freelance_external_id' => 'FR-001',
        ]);

        $lead = $lead->fresh();
        $this->assertTrue($lead->is_freelance);
        $this->assertSame(SalesLeadStatus::SiteVisit, $lead->current_status);
        $this->assertSame(SalesLeadStatus::SiteVisit, app(SalesLeadLifecycleService::class)->resolvePrimaryStatus([
            $lead->current_status,
            SalesLeadStatus::Freelance,
        ]));
    }

    public function test_history_is_idempotent_by_branch_operation_and_metadata_is_allowlisted(): void
    {
        [$lead, $actor] = $this->context();
        $service = app(SalesLeadLifecycleService::class);
        $operationUuid = (string) Str::uuid();

        $first = $service->recordStatusHistory(
            $lead,
            'site_visit',
            'site_visit',
            'VISIT-1',
            $actor,
            metadata: ['reason' => 'surveyed', 'nik' => '001234567890'],
            operationUuid: $operationUuid,
        );
        $second = $service->recordStatusHistory(
            $lead,
            'site_visit',
            'site_visit',
            'VISIT-1',
            $actor,
            operationUuid: $operationUuid,
        );

        $this->assertTrue($first->is($second));
        $this->assertSame(['reason' => 'surveyed'], $first->metadata);
        $this->assertSame(1, SalesLeadStatusHistory::where('operation_uuid', $operationUuid)->count());
    }

    public function test_backfill_uses_only_survey_utj_and_akad_with_highest_status_winning(): void
    {
        [$lead] = $this->context([
            'contacted_at' => '2026-01-01 08:00:00',
            'met_at' => '2026-01-02 08:00:00',
            'documents_completed_at' => '2026-01-05 08:00:00',
        ]);
        [$survey] = $this->context(['surveyed_at' => '2026-02-01 08:00:00']);
        [$utj] = $this->context(['surveyed_at' => '2026-02-01 08:00:00', 'utj_at' => '2026-02-02 08:00:00']);
        [$akad] = $this->context([
            'surveyed_at' => '2026-02-01 08:00:00',
            'utj_at' => '2026-02-02 08:00:00',
            'akad_at' => '2026-02-03 08:00:00',
        ]);

        $migration = require database_path('migrations/2026_08_03_000007_backfill_sales_lead_current_status.php');
        $migration->up();

        $this->assertSame(SalesLeadStatus::NoResponse, $lead->fresh()->current_status);
        $this->assertSame(SalesLeadStatus::SiteVisit, $survey->fresh()->current_status);
        $this->assertSame(SalesLeadStatus::Utj, $utj->fresh()->current_status);
        $this->assertSame(SalesLeadStatus::Akad, $akad->fresh()->current_status);
        $this->assertSame('akad_at', $akad->fresh()->current_status_source_id);
    }

    public function test_project_nup_defaults_false_and_external_ids_are_unique_per_branch(): void
    {
        [$lead] = $this->context(['external_lead_id' => 'EXT-1']);

        $this->assertFalse($lead->project->is_nup_eligible);
        $otherBranch = Branch::create(['name' => 'Other', 'code' => 'OTH', 'is_active' => true]);
        $otherProject = LeadMaster::create(['branch_id' => $otherBranch->id, 'project_name' => 'Other Project']);
        $otherActor = User::factory()->create(['branch_id' => $otherBranch->id]);
        SalesLead::create($this->leadData($otherProject, $otherActor) + ['external_lead_id' => 'EXT-1']);

        $this->assertSame(2, SalesLead::where('external_lead_id', 'EXT-1')->count());
        $this->assertSame('varchar', DB::selectOne("select type from pragma_table_info('sales_lead_slik_attempts') where name = 'nik'")->type);
    }

    private function context(array $leadOverrides = []): array
    {
        $branch = Branch::create([
            'name' => 'Branch '.Str::random(8),
            'code' => strtoupper(Str::random(6)),
            'is_active' => true,
        ]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Project '.Str::random(8)]);
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $lead = SalesLead::create(array_merge($this->leadData($project, $actor), $leadOverrides));

        return [$lead, $actor];
    }

    private function leadData(LeadMaster $project, User $actor): array
    {
        return [
            'branch_id' => $project->branch_id,
            'project_id' => $project->id,
            'sales_user_id' => $actor->id,
            'lead_date' => '2026-08-03',
            'customer_name' => 'Lifecycle Lead',
        ];
    }
}
