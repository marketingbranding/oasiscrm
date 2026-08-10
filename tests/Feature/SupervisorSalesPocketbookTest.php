<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\OrganizationScopeService;
use App\Services\SupervisorSalesMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SupervisorSalesPocketbookTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $outsideBranch;

    private LeadMaster $project;

    private LeadMaster $outsideProject;

    private User $supervisor;

    private User $otherSupervisor;

    private User $coordinatorA;

    private User $coordinatorB;

    private User $otherCoordinator;

    private User $sales1;

    private User $sales2;

    private User $sales3;

    private User $outsideSales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-10 10:00:00', config('app.timezone')));
        $this->branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $this->outsideBranch = Branch::create(['name' => 'Luar', 'code' => 'LUA', 'is_active' => true]);
        $this->project = LeadMaster::create(['branch_id' => $this->branch->id, 'project_name' => 'Proyek Solo', 'is_active' => true]);
        $this->outsideProject = LeadMaster::create(['branch_id' => $this->outsideBranch->id, 'project_name' => 'Proyek Luar', 'is_active' => true]);
        $this->supervisor = $this->user('supervisor', 'SPV A', $this->branch);
        $this->otherSupervisor = $this->user('supervisor', 'SPV B', $this->outsideBranch);
        $this->coordinatorA = $this->user('sales_coordinator', 'Koordinator A', $this->branch, $this->supervisor);
        $this->coordinatorB = $this->user('sales_coordinator', 'Koordinator B', $this->branch, $this->supervisor);
        $this->otherCoordinator = $this->user('sales_coordinator', 'Koordinator SPV B', $this->outsideBranch, $this->otherSupervisor);
        $this->sales1 = $this->user('sales', 'Sales 1', $this->branch, $this->coordinatorA);
        $this->sales2 = $this->user('sales', 'Sales 2', $this->branch, $this->coordinatorA);
        $this->sales3 = $this->user('sales', 'Sales 3', $this->branch, $this->coordinatorB);
        $this->outsideSales = $this->user('sales', 'Sales Luar', $this->outsideBranch, $this->otherCoordinator);
        foreach ([$this->sales1, $this->sales2, $this->sales3] as $sales) {
            $sales->assignedProjects()->attach($this->project, ['is_primary' => true, 'is_active' => true]);
        }
        $this->outsideSales->assignedProjects()->attach($this->outsideProject, ['is_primary' => true, 'is_active' => true]);

        foreach ([[$this->coordinatorA, $this->sales1], [$this->coordinatorA, $this->sales2], [$this->coordinatorB, $this->sales3], [$this->otherCoordinator, $this->outsideSales]] as [$coordinator, $sales]) {
            SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id]);
        }
        SalesCoordinatorSales::create(['coordinator_user_id' => $this->coordinatorB->id, 'sales_user_id' => $this->sales1->id]);
        SalesCoordinatorSales::create(['coordinator_user_id' => $this->coordinatorA->id, 'sales_user_id' => $this->sales3->id, 'is_active' => false, 'ended_at' => today()->subDay()]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_shared_route_dispatches_by_primary_role_and_supplemental_supervisor_does_not_escalate(): void
    {
        $sales = $this->sales1;
        $coordinator = $this->coordinatorA;
        $supplemental = $this->user('sales', 'Sales Supplemental SPV', $this->branch);
        $supplemental->roles()->attach(Role::where('slug', 'supervisor')->firstOrFail());

        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()->assertViewIs('crm.sales-pocketbook.sales-agenda');
        $this->actingAs($coordinator)->get(route('sales-pocketbook.index'))->assertOk()->assertViewIs('crm.sales-pocketbook.coordinator-leads');
        $this->actingAs($this->supervisor)->get(route('sales-pocketbook.index'))->assertOk()->assertViewIs('crm.sales-pocketbook.supervisor-monitoring');
        $this->actingAs($supplemental)->get(route('sales-pocketbook.index'))->assertOk()->assertViewIs('crm.sales-pocketbook.sales-agenda');
    }

    public function test_team_uses_current_direct_hierarchy_access_and_unique_mappings(): void
    {
        $this->assertContains($this->project->id, app(OrganizationScopeService::class)->projectIds($this->supervisor, 'sales_pocketbook', 'view'));
        $data = $this->resolve(['period' => 'today']);

        $this->assertSame([$this->coordinatorA->id, $this->coordinatorB->id], $data['coordinators']->pluck('id')->sort()->values()->all());
        $this->assertSame([$this->sales1->id, $this->sales2->id, $this->sales3->id], $data['salesUsers']->pluck('id')->sort()->values()->all());
        $this->assertCount(3, $data['salesRows']);
        $this->assertSame(['Koordinator A', 'Koordinator B'], $data['coordinatorNamesBySalesId'][$this->sales1->id]);
        $this->assertArrayNotHasKey($this->outsideSales->id, $data['coordinatorNamesBySalesId']);
    }

    public function test_branch_scope_excludes_descendants_and_mappings_outside_accessible_branch(): void
    {
        $outsideCoordinator = $this->user('sales_coordinator', 'Koordinator Hierarki Luar', $this->outsideBranch, $this->supervisor);
        $outsideHierarchySales = $this->user('sales', 'Sales Hierarki Luar', $this->outsideBranch, $outsideCoordinator);
        SalesCoordinatorSales::create(['coordinator_user_id' => $outsideCoordinator->id, 'sales_user_id' => $outsideHierarchySales->id]);

        $data = $this->resolve([]);

        $this->assertNotContains($outsideCoordinator->id, $data['coordinators']->pluck('id'));
        $this->assertNotContains($outsideHierarchySales->id, $data['salesUsers']->pluck('id'));
    }

    public function test_forged_coordinator_and_sales_filters_return_403(): void
    {
        $this->actingAs($this->supervisor)->get(route('sales-pocketbook.index', ['coordinator_id' => $this->otherCoordinator->id]))->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('sales-pocketbook.index', ['sales_id' => $this->outsideSales->id]))->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('sales-pocketbook.index', ['coordinator_id' => $this->coordinatorA->id, 'sales_id' => $this->sales3->id]))->assertForbidden();
    }

    public function test_today_week_month_and_custom_kpis_count_unique_team_records(): void
    {
        $this->agenda($this->sales1, 'Agenda Hari Ini Selesai', '2026-08-10', 'done', 'Berhasil');
        $this->agenda($this->sales1, 'Agenda Minggu', '2026-08-11');
        $this->agenda($this->sales2, 'Agenda Bulan', '2026-08-20', 'done', 'Berhasil');
        $this->agenda($this->sales3, 'Agenda Luar Bulan', '2026-09-01');
        $this->lead($this->sales1, 'Lead Today Pending Create', '2026-08-10', 'pending_create');
        $this->lead($this->sales1, 'Lead Week Pending Update', '2026-08-11', 'pending_update');
        $this->lead($this->sales2, 'Lead Month Failed', '2026-08-20', 'sync_failed');
        $this->lead($this->sales3, 'Lead Outside Month', '2026-09-01', 'synced');

        $this->assertSame([2, 3, 1, 1, 1, 1, 0, 0], array_values($this->resolve(['period' => 'today'])['kpi']));
        $this->assertSame([2, 3, 2, 1, 2, 1, 1, 0], array_values($this->resolve(['period' => 'week'])['kpi']));
        $this->assertSame([2, 3, 3, 2, 3, 1, 1, 1], array_values($this->resolve(['period' => 'month'])['kpi']));
        $this->assertSame([2, 3, 1, 1, 1, 0, 0, 1], array_values($this->resolve(['period' => 'custom', 'date_from' => '2026-08-20', 'date_to' => '2026-08-20'])['kpi']));
    }

    public function test_attention_and_coordinator_and_sales_aggregates_are_exact(): void
    {
        $this->agenda($this->sales1, 'Agenda Tanpa Hasil', '2026-08-10');
        $this->agenda($this->sales1, 'Agenda Selesai', '2026-08-10', 'done', 'Deal');
        $this->lead($this->sales1, 'Lead Create', '2026-08-10', 'pending_create');
        $this->lead($this->sales2, 'Lead Update', '2026-08-10', 'pending_update');
        $this->lead($this->sales3, 'Lead Failed', '2026-08-10', 'sync_failed');
        $data = $this->resolve([]);

        $this->assertSame([$this->sales2->id, $this->sales3->id], $data['attention']['without_agenda']->pluck('id')->sort()->values()->all());
        $this->assertSame([$this->sales1->id, $this->sales2->id, $this->sales3->id], $data['attention']['pending']->pluck('id')->sort()->values()->all());
        $this->assertSame([$this->sales1->id], $data['attention']['missing_result']->pluck('id')->all());

        $sales1 = $data['salesRows']->firstWhere('id', $this->sales1->id);
        $this->assertSame([2, 1, 1, 1, 1, 0, 0], [$sales1->agenda_count, $sales1->agenda_done, $sales1->missing_result, $sales1->lead_count, $sales1->pending_create, $sales1->pending_update, $sales1->sync_failed]);
        $coordinatorA = $data['coordinatorRows']->firstWhere('id', $this->coordinatorA->id);
        $coordinatorB = $data['coordinatorRows']->firstWhere('id', $this->coordinatorB->id);
        $this->assertSame([2, 2, 1, 1, 0], [$coordinatorA->sales_count, $coordinatorA->lead_count, $coordinatorA->pending_create, $coordinatorA->pending_update, $coordinatorA->sync_failed]);
        $this->assertSame([2, 2, 1, 0, 1], [$coordinatorB->sales_count, $coordinatorB->lead_count, $coordinatorB->pending_create, $coordinatorB->pending_update, $coordinatorB->sync_failed]);
    }

    public function test_runtime_empty_states_cover_no_coordinator_no_sales_no_agenda_and_no_lead(): void
    {
        $emptySupervisor = $this->user('supervisor', 'SPV Kosong', $this->branch);
        $this->actingAs($emptySupervisor)->get(route('sales-pocketbook.index'))->assertOk()->assertSee('Belum ada Koordinator yang ditugaskan ke Anda.');

        $emptyCoordinator = $this->user('sales_coordinator', 'Koordinator Kosong', $this->branch, $emptySupervisor);
        $this->actingAs($emptySupervisor)->get(route('sales-pocketbook.index'))->assertOk()->assertSee('Koordinator belum memiliki Sales aktif.');

        $response = $this->actingAs($this->supervisor)->get(route('sales-pocketbook.index', ['sales_id' => $this->sales2->id]));
        $response->assertOk()->assertSee('Belum ada agenda pada periode ini.')->assertSee('Belum ada lead pada periode ini.');
    }

    public function test_record_scope_excludes_same_sales_records_outside_allowed_project_and_branch_everywhere(): void
    {
        $outsideProjectSameBranch = LeadMaster::create([
            'branch_id' => $this->branch->id,
            'project_name' => 'Proyek Tidak Ditugaskan',
            'is_active' => true,
        ]);
        $allowedAgenda = $this->agenda($this->sales1, 'AGENDA_SCOPE_ALLOWED', '2026-08-10');
        $outsideProjectAgenda = $this->agenda($this->sales1, 'AGENDA_SCOPE_PROJECT_HIDDEN', '2026-08-10');
        $outsideProjectAgenda->update(['sales_project_id' => $outsideProjectSameBranch->id]);
        $outsideBranchAgenda = $this->agenda($this->sales1, 'AGENDA_SCOPE_BRANCH_HIDDEN', '2026-08-10');
        $outsideBranchAgenda->update(['branch_id' => $this->outsideBranch->id, 'sales_project_id' => $this->outsideProject->id]);
        $allowedLead = $this->lead($this->sales1, 'LEAD_SCOPE_ALLOWED', '2026-08-10', 'pending_create');
        $outsideProjectLead = $this->lead($this->sales1, 'LEAD_SCOPE_PROJECT_HIDDEN', '2026-08-10', 'sync_failed');
        $outsideProjectLead->update(['project_id' => $outsideProjectSameBranch->id]);
        $outsideBranchLead = $this->lead($this->sales1, 'LEAD_SCOPE_BRANCH_HIDDEN', '2026-08-10', 'pending_update');
        $outsideBranchLead->update(['branch_id' => $this->outsideBranch->id, 'project_id' => $this->outsideProject->id]);

        $data = $this->resolve(['period' => 'today', 'sales_id' => $this->sales1->id]);
        $this->assertSame([1, 1, 0, 0], [$data['kpi']['agenda_count'], $data['kpi']['lead_count'], $data['kpi']['pending_update'], $data['kpi']['sync_failed']]);
        $this->assertSame([$allowedAgenda->id], $data['agendas']->pluck('id')->all());
        $this->assertSame([$allowedLead->id], $data['leads']->pluck('id')->all());

        $response = $this->actingAs($this->supervisor)->get(route('sales-pocketbook.index', ['period' => 'today', 'sales_id' => $this->sales1->id]))->assertOk();
        $response->assertSee($allowedAgenda->title)->assertSee($allowedLead->customer_name)
            ->assertDontSee($outsideProjectAgenda->title)->assertDontSee($outsideBranchAgenda->title)
            ->assertDontSee($outsideProjectLead->customer_name)->assertDontSee($outsideBranchLead->customer_name);

        $agendaResponse = $this->actingAs($this->supervisor)->get(route('sales-pocketbook.supervisor-monitoring.agenda-export', ['period' => 'today', 'sales_id' => $this->sales1->id]))->assertOk();
        $agendaPath = $agendaResponse->baseResponse->getFile()->getPathname();
        $agendaSheet = IOFactory::load($agendaPath)->getActiveSheet();
        $this->assertSame(2, $agendaSheet->getHighestDataRow());
        $this->assertSame($allowedAgenda->title, $agendaSheet->getCell('F2')->getValue());
        @unlink($agendaPath);

        $leadResponse = $this->actingAs($this->supervisor)->get(route('sales-pocketbook.supervisor-monitoring.lead-export', ['period' => 'today', 'sales_id' => $this->sales1->id]))->assertOk();
        $leadPath = $leadResponse->baseResponse->getFile()->getPathname();
        $leadSheet = IOFactory::load($leadPath)->getActiveSheet();
        $this->assertSame(2, $leadSheet->getHighestDataRow());
        $this->assertSame($allowedLead->customer_name, $leadSheet->getCell('D2')->getValue());
        @unlink($leadPath);
    }

    public function test_team_excludes_sales_without_current_assignment_in_allowed_projects(): void
    {
        $unassigned = $this->user('sales', 'Sales Tanpa Proyek Scope', $this->branch, $this->coordinatorA);
        $unassigned->assignedProjects()->attach($this->outsideProject, ['is_primary' => true, 'is_active' => true]);
        SalesCoordinatorSales::create(['coordinator_user_id' => $this->coordinatorA->id, 'sales_user_id' => $unassigned->id]);

        $data = $this->resolve([]);

        $this->assertNotContains($unassigned->id, $data['salesUsers']->pluck('id'));
        $this->assertArrayNotHasKey($unassigned->id, $data['coordinatorNamesBySalesId']);
    }

    public function test_supervisor_exports_only_visible_period_records_without_duplicate_business_rows(): void
    {
        $visibleAgenda = $this->agenda($this->sales1, 'AGENDA_EXPORT_VISIBLE', '2026-08-10');
        $this->agenda($this->outsideSales, 'AGENDA_EXPORT_HIDDEN', '2026-08-10');
        $visibleLead = $this->lead($this->sales1, 'LEAD_EXPORT_VISIBLE', '2026-08-10', 'pending_update');
        $this->lead($this->outsideSales, 'LEAD_EXPORT_HIDDEN', '2026-08-10', 'sync_failed');

        $agendaResponse = $this->actingAs($this->supervisor)->get(route('sales-pocketbook.supervisor-monitoring.agenda-export', ['period' => 'today']))->assertOk();
        $agendaPath = $agendaResponse->baseResponse->getFile()->getPathname();
        $agendaSheet = IOFactory::load($agendaPath)->getActiveSheet();
        $this->assertSame(2, $agendaSheet->getHighestDataRow());
        $this->assertSame($visibleAgenda->title, $agendaSheet->getCell('F2')->getValue());
        $this->assertSame('Koordinator A; Koordinator B', $agendaSheet->getCell('B2')->getValue());
        @unlink($agendaPath);

        $leadResponse = $this->actingAs($this->supervisor)->get(route('sales-pocketbook.supervisor-monitoring.lead-export', ['period' => 'today']))->assertOk();
        $leadPath = $leadResponse->baseResponse->getFile()->getPathname();
        $leadSheet = IOFactory::load($leadPath)->getActiveSheet();
        $this->assertSame(2, $leadSheet->getHighestDataRow());
        $this->assertSame($visibleLead->customer_name, $leadSheet->getCell('D2')->getValue());
        $this->assertSame('Perlu Sync Ulang', $leadSheet->getCell('L2')->getValue());
        @unlink($leadPath);
    }

    public function test_selected_sales_detail_contains_only_selected_records_and_no_write_or_sync_routes(): void
    {
        $selectedAgenda = $this->agenda($this->sales1, 'AGENDA_SELECTED_ONLY', '2026-08-10');
        $otherAgenda = $this->agenda($this->sales2, 'AGENDA_OTHER_HIDDEN', '2026-08-10');
        $selectedLead = $this->lead($this->sales1, 'LEAD_SELECTED_ONLY', '2026-08-10', 'synced');
        $otherLead = $this->lead($this->sales2, 'LEAD_OTHER_HIDDEN', '2026-08-10', 'synced');

        $response = $this->actingAs($this->supervisor)->get(route('sales-pocketbook.index', ['sales_id' => $this->sales1->id]));

        $response->assertOk()
            ->assertSee($selectedAgenda->title)
            ->assertSee($selectedLead->customer_name)
            ->assertDontSee($otherAgenda->title)
            ->assertDontSee($otherLead->customer_name)
            ->assertDontSee(route('sales-leads.edit', $selectedLead), false)
            ->assertDontSee(route('sales-leads.update', $selectedLead), false)
            ->assertDontSee(route('sales-agendas.update', $selectedAgenda), false)
            ->assertDontSee(route('coordinator-leads.sync'), false)
            ->assertDontSee(route('sales-pocketbook.lifecycle-sync'), false);
    }

    private function resolve(array $filters): array
    {
        return app(SupervisorSalesMonitoringService::class)->resolve($this->supervisor, $filters);
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

    private function agenda(User $sales, string $title, string $date, string $status = 'planned', ?string $result = null): ContentItem
    {
        return ContentItem::create([
            'branch_id' => $sales->branch_id,
            'sales_project_id' => $sales->branch_id === $this->branch->id ? $this->project->id : $this->outsideProject->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => $title,
            'scheduled_date' => $date,
            'status' => $status,
            'activity_result' => $result,
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
    }

    private function lead(User $sales, string $name, string $date, string $syncStatus): SalesLead
    {
        return SalesLead::create([
            'branch_id' => $sales->branch_id,
            'project_id' => $sales->branch_id === $this->branch->id ? $this->project->id : $this->outsideProject->id,
            'sales_user_id' => $sales->id,
            'lead_date' => $date,
            'customer_name' => $name,
            'sync_status' => $syncStatus,
            'created_by' => $sales->id,
        ]);
    }
}
