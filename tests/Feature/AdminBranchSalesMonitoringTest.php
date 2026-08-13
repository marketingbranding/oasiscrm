<?php

namespace Tests\Feature;

use App\Enums\SalesLeadStatus;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\GoogleSheetsApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class AdminBranchSalesMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    private Branch $foreignBranch;

    private LeadMaster $project;

    private LeadMaster $secondProject;

    private LeadMaster $foreignProject;

    private User $coordinator;

    private User $secondCoordinator;

    private User $historicalCoordinator;

    private User $inactiveCoordinator;

    private User $sales;

    private User $secondSales;

    private User $unmappedSales;

    private User $foreignSales;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.local_only' => false]);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldNotReceive(Mockery::any());
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $this->branch = Branch::create(['name' => 'Cabang Admin', 'code' => 'ADM', 'is_active' => true]);
        $this->foreignBranch = Branch::create(['name' => 'Cabang Asing', 'code' => 'FOR', 'is_active' => true]);
        $this->project = $this->project($this->branch, 'Proyek Admin');
        $this->secondProject = $this->project($this->branch, 'Proyek Admin Dua');
        $this->foreignProject = $this->project($this->foreignBranch, 'Proyek Asing');

        $this->admin = $this->user('admin', $this->branch, 'Admin Cabang');
        $this->coordinator = $this->user('sales_coordinator', $this->branch, 'Koordinator Aktif');
        $this->secondCoordinator = $this->user('sales_coordinator', $this->branch, 'Koordinator Kedua');
        $this->historicalCoordinator = $this->user('sales_coordinator', $this->branch, 'Koordinator Historis');
        $this->inactiveCoordinator = $this->user('sales_coordinator', $this->branch, 'Koordinator Nonaktif', false);
        $this->sales = $this->user('sales', $this->branch, 'Sales Utama');
        $this->secondSales = $this->user('sales', $this->branch, 'Sales Kedua');
        $this->unmappedSales = $this->user('sales', $this->branch, 'Sales Tanpa Koordinator');
        $this->foreignSales = $this->user('sales', $this->foreignBranch, 'Sales Asing');

        foreach ([$this->sales, $this->secondSales, $this->unmappedSales] as $sales) {
            $this->assign($sales, $this->project);
        }
        $this->assign($this->secondSales, $this->secondProject);
        $this->assign($this->foreignSales, $this->foreignProject);

        $this->mapping($this->coordinator, $this->sales);
        $this->mapping($this->secondCoordinator, $this->secondSales);
        $this->mapping($this->historicalCoordinator, $this->sales, true, today()->subYear()->toDateString(), today()->subMonth()->toDateString());
        $this->mapping($this->inactiveCoordinator, $this->sales);
    }

    public function test_primary_admin_leads_are_compact_read_only_and_use_latest_status_activity(): void
    {
        $lead = $this->lead($this->sales, $this->project, 'Konsumen Admin', 'Referensi', 'Instagram', SalesLeadStatus::Discussion);
        $this->history($lead, SalesLeadStatus::FaceToFace, '2026-08-05 09:00:00', 'older');
        $this->history($lead, SalesLeadStatus::SiteVisit, '2026-08-09 15:30:00', 'latest');

        $response = $this->actingAs($this->admin)->get($this->url());

        $response->assertOk()->assertViewIs('crm.sales-pocketbook.admin-monitoring')
            ->assertSee('Admin Cabang · Monitoring Read Only')
            ->assertSeeTextInOrder(['Tanggal Lead', 'Nama Konsumen', 'Sales', 'Koordinator', 'Proyek', 'Sumber', 'Kanal / Aktivitas', 'Status Lead', 'Aktivitas Terbaru', 'Detail'])
            ->assertSee('Leads')->assertSee('Agenda')->assertDontSee('tab=report', false)
            ->assertSee('Konsumen Admin')->assertSee('Koordinator Aktif')->assertSee('09/08/2026 15:30')
            ->assertDontSee('Koordinator Historis')->assertDontSee('Koordinator Nonaktif')
            ->assertDontSee('Sinkronisasi Lifecycle')->assertDontSee('Status Sync')->assertDontSee('Sync Lead')
            ->assertDontSee('Tambah Lead')->assertDontSee('Edit Lead')->assertDontSee('Hapus Lead')
            ->assertDontSee('Catat Site Visit')->assertDontSee('Konversi Konsumen')->assertDontSee('Ajukan SLIK');
        $response->assertViewHas('leads', fn ($leads) => $leads->first()->latest_activity_status === SalesLeadStatus::SiteVisit);
    }

    public function test_monitoring_is_own_branch_and_includes_sales_without_coordinator(): void
    {
        $this->lead($this->sales, $this->project, 'LEAD_OWN');
        $this->lead($this->unmappedSales, $this->project, 'LEAD_UNMAPPED');
        $this->lead($this->foreignSales, $this->foreignProject, 'LEAD_FOREIGN');

        $this->actingAs($this->admin)->get($this->url())->assertOk()
            ->assertSee('LEAD_OWN')->assertSee('LEAD_UNMAPPED')->assertSee('Sales Tanpa Koordinator')
            ->assertDontSee('LEAD_FOREIGN')->assertDontSee('Sales Asing')->assertDontSee('Cabang Asing');
    }

    public function test_agenda_is_own_branch_exact_columns_and_read_only(): void
    {
        $this->agenda($this->sales, $this->project, 'AGENDA_OWN', 'Follow-up', 'planned');
        $this->agenda($this->foreignSales, $this->foreignProject, 'AGENDA_FOREIGN', 'Canvassing', 'done');

        $response = $this->actingAs($this->admin)->get($this->url(['tab' => 'agenda']));

        $response->assertOk()
            ->assertSeeTextInOrder(['Tanggal', 'Sales', 'Koordinator', 'Proyek', 'Kategori', 'Agenda', 'Lokasi', 'Hasil', 'Status'])
            ->assertSee('AGENDA_OWN')->assertDontSee('AGENDA_FOREIGN')->assertDontSee('Cabang Asing')
            ->assertDontSee('Tambah Agenda')->assertDontSee('Edit Agenda')->assertDontSee('Hapus Agenda')
            ->assertDontSee('Simpan Hasil')->assertDontSee('Jadwalkan Ulang');
    }

    public function test_lead_filters_apply_date_project_coordinator_sales_source_platform_and_status(): void
    {
        $matching = $this->lead($this->sales, $this->project, 'LEAD_MATCH', 'Referensi', 'Instagram', SalesLeadStatus::Discussion, '2026-08-10');
        $this->lead($this->sales, $this->project, 'LEAD_WRONG_SOURCE', 'Pameran', 'Instagram', SalesLeadStatus::Discussion, '2026-08-10');
        $this->lead($this->sales, $this->project, 'LEAD_WRONG_PLATFORM', 'Referensi', 'TikTok', SalesLeadStatus::Discussion, '2026-08-10');
        $this->lead($this->sales, $this->project, 'LEAD_WRONG_STATUS', 'Referensi', 'Instagram', SalesLeadStatus::SiteVisit, '2026-08-10');
        $this->lead($this->secondSales, $this->project, 'LEAD_WRONG_SALES', 'Referensi', 'Instagram', SalesLeadStatus::Discussion, '2026-08-10');
        $this->lead($this->sales, $this->secondProject, 'LEAD_WRONG_PROJECT', 'Referensi', 'Instagram', SalesLeadStatus::Discussion, '2026-08-10');
        $this->lead($this->sales, $this->project, 'LEAD_WRONG_DATE', 'Referensi', 'Instagram', SalesLeadStatus::Discussion, '2026-07-31');

        $response = $this->actingAs($this->admin)->get($this->url([
            'date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'project_id' => $this->project->id,
            'coordinator_id' => $this->coordinator->id, 'sales_user_id' => $this->sales->id,
            'source' => 'Referensi', 'platform' => 'Instagram', 'status' => SalesLeadStatus::Discussion->value,
        ]));

        $response->assertOk()->assertSee($matching->customer_name);
        foreach (['LEAD_WRONG_SOURCE', 'LEAD_WRONG_PLATFORM', 'LEAD_WRONG_STATUS', 'LEAD_WRONG_SALES', 'LEAD_WRONG_PROJECT', 'LEAD_WRONG_DATE'] as $excluded) {
            $response->assertDontSee($excluded);
        }
    }

    public function test_agenda_filters_apply_date_project_coordinator_sales_category_and_status(): void
    {
        $this->agenda($this->sales, $this->project, 'AGENDA_MATCH', 'Follow-up', 'done', '2026-08-10');
        $this->agenda($this->sales, $this->project, 'AGENDA_WRONG_CATEGORY', 'Canvassing', 'done', '2026-08-10');
        $this->agenda($this->sales, $this->project, 'AGENDA_WRONG_STATUS', 'Follow-up', 'planned', '2026-08-10');
        $this->agenda($this->secondSales, $this->project, 'AGENDA_WRONG_SALES', 'Follow-up', 'done', '2026-08-10');
        $this->agenda($this->sales, $this->secondProject, 'AGENDA_WRONG_PROJECT', 'Follow-up', 'done', '2026-08-10');
        $this->agenda($this->sales, $this->project, 'AGENDA_WRONG_DATE', 'Follow-up', 'done', '2026-07-31');

        $response = $this->actingAs($this->admin)->get($this->url([
            'tab' => 'agenda', 'date_from' => '2026-08-01', 'date_to' => '2026-08-31',
            'project_id' => $this->project->id, 'coordinator_id' => $this->coordinator->id,
            'sales_user_id' => $this->sales->id, 'agenda_category' => 'Follow-up', 'agenda_status' => 'done',
        ]));

        $response->assertOk()->assertSee('AGENDA_MATCH');
        foreach (['AGENDA_WRONG_CATEGORY', 'AGENDA_WRONG_STATUS', 'AGENDA_WRONG_SALES', 'AGENDA_WRONG_PROJECT', 'AGENDA_WRONG_DATE'] as $excluded) {
            $response->assertDontSee($excluded);
        }
    }

    public function test_foreign_and_mismatched_explicit_scope_filters_are_forbidden(): void
    {
        foreach ([
            ['branch_id' => $this->foreignBranch->id],
            ['project_id' => $this->foreignProject->id],
            ['coordinator_id' => $this->historicalCoordinator->id],
            ['sales_user_id' => $this->foreignSales->id],
            ['coordinator_id' => $this->coordinator->id, 'sales_user_id' => $this->secondSales->id],
        ] as $filters) {
            $this->actingAs($this->admin)->get($this->url($filters))->assertForbidden();
        }
    }

    public function test_report_tab_redirects_to_fee_report_preserving_dates_and_fee_report_remains_available(): void
    {
        $this->actingAs($this->admin)->get($this->url([
            'tab' => 'report', 'period' => '2026-08', 'date_from' => '2026-08-03', 'date_to' => '2026-08-20',
        ]))->assertRedirect(route('sales-fee-reports.index', [
            'period' => '2026-08', 'date_from' => '2026-08-03', 'date_to' => '2026-08-20',
        ]));

        $this->actingAs($this->admin)->get(route('sales-fee-reports.index', [
            'date_from' => '2026-08-01', 'date_to' => '2026-08-31',
        ]))->assertOk()->assertSee('Laporan Fee Sales');
    }

    public function test_other_primary_roles_keep_existing_specialized_or_shared_dispatch(): void
    {
        $sales = $this->user('sales', $this->branch, 'Dispatch Sales');
        $this->assign($sales, $this->project);
        $coordinator = $this->user('sales_coordinator', $this->branch, 'Dispatch Coordinator');
        $supervisor = $this->user('supervisor', $this->branch, 'Dispatch Supervisor');
        $manager = $this->user('manager', $this->branch, 'Dispatch Manager');

        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()->assertViewIs('crm.sales-pocketbook.sales-agenda');
        $this->actingAs($coordinator)->get(route('sales-pocketbook.index'))->assertOk()->assertViewIs('crm.sales-pocketbook.coordinator-leads');
        $this->actingAs($supervisor)->get(route('sales-pocketbook.index'))->assertOk()->assertViewIs('crm.sales-pocketbook.supervisor-monitoring');
        $this->actingAs($manager)->get(route('sales-pocketbook.index'))->assertOk()->assertViewIs('crm.sales-pocketbook.index');
    }

    public function test_fee_print_keeps_wysiwyg_a4_contract(): void
    {
        $response = $this->actingAs($this->admin)->get(route('sales-fee-reports.print', [
            $this->sales, $this->project, 'date_from' => '2026-08-01', 'date_to' => '2026-08-31',
        ]));

        $response->assertOk()
            ->assertSee('class="screen-toolbar screen-only"', false)
            ->assertSee('class="print-sheet"', false)
            ->assertSee('@page { size: A4 portrait; margin: 0; }', false)
            ->assertSee('.print-sheet { width: 210mm; min-height: auto; margin: 0; padding: 12mm; box-shadow: none; }', false);
    }

    private function url(array $query = []): string
    {
        return route('sales-pocketbook.index', $query + ['date_from' => '2026-08-01', 'date_to' => '2026-08-31']);
    }

    private function project(Branch $branch, string $name): LeadMaster
    {
        return LeadMaster::create(['branch_id' => $branch->id, 'project_name' => $name, 'is_active' => true]);
    }

    private function user(string $role, Branch $branch, string $name, bool $active = true): User
    {
        $user = User::factory()->create([
            'name' => $name, 'role_id' => Role::query()->where('slug', $role)->value('id'), 'branch_id' => $branch->id,
            'is_active' => $active, 'account_status' => $active ? 'active' : 'inactive', 'password_changed_at' => now(),
        ]);
        $user->branches()->syncWithoutDetaching([$branch->id => [
            'membership_role' => 'primary', 'can_view' => true, 'can_edit' => false, 'can_sync' => false, 'can_manage_members' => false,
        ]]);

        return $user;
    }

    private function assign(User $sales, LeadMaster $project): void
    {
        $sales->assignedProjects()->syncWithoutDetaching([$project->id => [
            'is_primary' => false, 'is_active' => true,
            'assignment_start_date' => today()->subMonth(), 'assignment_end_date' => today()->addMonth(),
        ]]);
    }

    private function mapping(User $coordinator, User $sales, bool $active = true, ?string $startedAt = null, ?string $endedAt = null): void
    {
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id, 'is_active' => $active,
            'started_at' => $startedAt ?? today()->subMonth(), 'ended_at' => $endedAt ?? today()->addMonth(),
        ]);
    }

    private function lead(User $sales, LeadMaster $project, string $name, string $source = 'Referensi', string $platform = 'Instagram', SalesLeadStatus $status = SalesLeadStatus::Discussion, string $date = '2026-08-10'): SalesLead
    {
        return SalesLead::create([
            'branch_id' => $project->branch_id, 'project_id' => $project->id, 'sales_user_id' => $sales->id,
            'lead_date' => $date, 'customer_name' => $name, 'source' => $source, 'platform' => $platform,
            'current_status' => $status, 'created_by' => $sales->id,
        ]);
    }

    private function agenda(User $sales, LeadMaster $project, string $title, string $category, string $status, string $date = '2026-08-10'): ContentItem
    {
        return ContentItem::create([
            'branch_id' => $project->branch_id, 'project_name' => $project->project_name,
            'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'visibility' => 'personal',
            'title' => $title, 'scheduled_date' => $date, 'status' => $status, 'sales_activity_category' => $category,
            'location' => 'Lokasi Test', 'activity_result' => 'Hasil Test',
            'owner_user_id' => $sales->id, 'sales_project_id' => $project->id, 'created_by' => $sales->id,
        ]);
    }

    private function history(SalesLead $lead, SalesLeadStatus $status, string $changedAt, string $sourceId): void
    {
        DB::table('sales_lead_status_histories')->insert([
            'sales_lead_id' => $lead->id, 'branch_id' => $lead->branch_id, 'actor_id' => $this->admin->id,
            'status' => $status->value, 'source' => 'test', 'source_id' => $sourceId,
            'changed_at' => $changedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
