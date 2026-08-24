<?php

namespace Tests\Feature;

use App\Enums\SalesLeadStatus;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\SalesLeadStatusHistory;
use App\Models\User;
use App\Services\CoordinatorSalesMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CoordinatorSalesMonitoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $outsideBranch;

    private LeadMaster $project;

    private LeadMaster $outsideProject;

    private User $coordinator;

    private User $sales;

    private User $outsideSales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-11 10:00:00', config('app.timezone')));
        $this->branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $this->outsideBranch = Branch::create(['name' => 'Luar', 'code' => 'LUA', 'is_active' => true]);
        $this->project = LeadMaster::create(['branch_id' => $this->branch->id, 'project_name' => 'Proyek Solo', 'is_active' => true]);
        $this->outsideProject = LeadMaster::create(['branch_id' => $this->outsideBranch->id, 'project_name' => 'Proyek Luar', 'is_active' => true]);
        $this->coordinator = $this->user('sales_coordinator', 'Koordinator', $this->branch);
        $this->sales = $this->user('sales', 'Sales Tim', $this->branch);
        $elsewhere = $this->user('sales_coordinator', 'Koordinator Lain', $this->outsideBranch);
        $this->outsideSales = $this->user('sales', 'Sales Luar', $this->outsideBranch, $elsewhere);
        $this->sales->assignedProjects()->attach($this->project, ['is_primary' => true, 'is_active' => true]);
        $this->outsideSales->assignedProjects()->attach($this->outsideProject, ['is_primary' => true, 'is_active' => true]);
        SalesCoordinatorSales::create(['coordinator_user_id' => $this->coordinator->id, 'sales_user_id' => $this->sales->id]);
        SalesCoordinatorSales::create(['coordinator_user_id' => $this->coordinator->id, 'sales_user_id' => $this->outsideSales->id]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_weekly_metrics_use_first_history_event_and_strict_scope(): void
    {
        $leads = collect();
        foreach (range(1, 10) as $index) {
            $leads->push($this->lead($this->sales, "Lead {$index}", '2026-08-11', $this->project, $this->branch));
        }
        foreach ($leads->take(4) as $lead) {
            $this->history($lead, SalesLeadStatus::FaceToFace, '2026-08-11 09:00:00');
        }
        foreach ($leads->take(3) as $lead) {
            $this->history($lead, SalesLeadStatus::SiteVisit, '2026-08-12 09:00:00');
        }
        foreach ($leads->take(2) as $lead) {
            $this->history($lead, SalesLeadStatus::Utj, '2026-08-13 09:00:00');
        }
        $this->history($leads->first(), SalesLeadStatus::FaceToFace, '2026-08-12 10:00:00');

        $outsidePeriod = $this->lead($this->sales, 'Outside period', '2026-08-03', $this->project, $this->branch);
        $this->history($outsidePeriod, SalesLeadStatus::Utj, '2026-08-03 09:00:00');
        $this->lead($this->outsideSales, 'Outside team scope', '2026-08-11', $this->outsideProject, $this->outsideBranch);
        $unassignedProject = LeadMaster::create(['branch_id' => $this->branch->id, 'project_name' => 'Proyek Tidak Ditugaskan', 'is_active' => true]);
        $this->lead($this->sales, 'Wrong project', '2026-08-11', $unassignedProject, $this->branch);
        $this->lead($this->sales, 'Wrong branch', '2026-08-11', $this->project, $this->outsideBranch);

        $data = app(CoordinatorSalesMonitoringService::class)->resolve($this->coordinator, [], false);

        $this->assertSame(['lead_new' => 10, 'face_to_face' => 4, 'site_visit' => 3, 'utj' => 2], $data['kpi']);
        $this->assertSame('week', $data['period']['key']);
        $this->assertSame([$this->sales->id], $data['salesUsers']->pluck('id')->all());
        $this->assertNull($data['salesRows']->firstWhere('id', $this->outsideSales->id));
        $this->assertNotContains('Wrong project', $data['leads']->pluck('customer_name'));
    }

    public function test_custom_period_applies_exact_dates_to_leads_agendas_and_metrics(): void
    {
        $included = $this->lead($this->sales, 'Dalam Rentang', '2026-08-06', $this->project, $this->branch);
        $this->lead($this->sales, 'Di Luar Rentang', '2026-08-08', $this->project, $this->branch);
        $this->history($included, SalesLeadStatus::FaceToFace, '2026-08-07 09:00:00');
        $this->agenda($this->sales, 'Agenda Dalam Rentang', $this->branch, $this->project, '2026-08-05');
        $this->agenda($this->sales, 'Agenda Di Luar Rentang', $this->branch, $this->project, '2026-08-08');

        $data = app(CoordinatorSalesMonitoringService::class)->resolve($this->coordinator, [
            'period' => 'custom',
            'date_from' => '2026-08-05',
            'date_to' => '2026-08-07',
        ], false);

        $this->assertSame('custom', $data['period']['key']);
        $this->assertSame('2026-08-05', $data['period']['from']->toDateString());
        $this->assertSame('2026-08-07', $data['period']['to']->toDateString());
        $this->assertSame(['Dalam Rentang'], $data['leads']->pluck('customer_name')->all());
        $this->assertSame(['Agenda Dalam Rentang'], $data['agendas']->pluck('title')->all());
        $this->assertSame(1, $data['kpi']['lead_new']);
        $this->assertSame(1, $data['kpi']['face_to_face']);
    }

    public function test_forged_sales_filter_is_forbidden(): void
    {
        $outsider = $this->user('sales', 'Bukan Tim', $this->branch);

        try {
            app(CoordinatorSalesMonitoringService::class)->resolve($this->coordinator, ['sales_id' => $outsider->id]);
            $this->fail('Expected 403.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_agendas_require_canonical_type_current_mapping_and_exact_scope(): void
    {
        $included = $this->agenda($this->sales, 'Included', $this->branch, $this->project, '2026-08-11');
        $this->agenda($this->sales, 'Wrong subtype', $this->branch, $this->project, '2026-08-11', 'other');
        $this->agenda($this->sales, 'Wrong branch', $this->outsideBranch, $this->project, '2026-08-11');
        $this->agenda($this->sales, 'Wrong project', $this->branch, $this->outsideProject, '2026-08-11');
        $this->agenda($this->sales, 'Outside period', $this->branch, $this->project, '2026-08-03');
        $this->agenda($this->outsideSales, 'Outside current scope', $this->outsideBranch, $this->outsideProject, '2026-08-11');
        SalesCoordinatorSales::where('sales_user_id', $this->sales->id)->update(['is_active' => false, 'ended_at' => today()->subDay()]);

        $withoutMapping = app(CoordinatorSalesMonitoringService::class)->resolve($this->coordinator, [], false);
        $this->assertCount(0, $withoutMapping['agendas']);

        SalesCoordinatorSales::create(['coordinator_user_id' => $this->coordinator->id, 'sales_user_id' => $this->sales->id]);
        $data = app(CoordinatorSalesMonitoringService::class)->resolve($this->coordinator, [], false);
        $this->assertSame([$included->id], $data['agendas']->pluck('id')->all());
        $this->assertTrue($data['agendas']->first()->relationLoaded('owner'));
    }

    private function user(string $role, string $name, Branch $branch, ?User $supervisor = null): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'role_id' => Role::where('slug', $role)->firstOrFail()->id,
            'branch_id' => $branch->id,
            'supervisor_user_id' => $supervisor?->id,
            'password_changed_at' => now(),
        ]);
        $user->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => false, 'can_sync' => false]]);

        return $user;
    }

    private function lead(User $sales, string $name, string $date, LeadMaster $project, Branch $branch): SalesLead
    {
        return SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => $date,
            'customer_name' => $name,
            'created_by' => $sales->id,
        ]);
    }

    private function history(SalesLead $lead, SalesLeadStatus $status, string $changedAt): void
    {
        $history = SalesLeadStatusHistory::create([
            'sales_lead_id' => $lead->id,
            'branch_id' => $lead->branch_id,
            'status' => $status,
            'source' => 'test',
            'source_id' => uniqid(),
            'changed_at' => $changedAt,
        ]);

        $this->assertSame($status, $history->fresh()->status);
    }

    private function agenda(User $sales, string $title, Branch $branch, LeadMaster $project, string $date, string $type = ContentItem::SALES_AGENDA_TYPE): ContentItem
    {
        return ContentItem::create([
            'branch_id' => $branch->id,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => $type,
            'visibility' => 'team',
            'title' => $title,
            'scheduled_date' => $date,
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
    }
}
