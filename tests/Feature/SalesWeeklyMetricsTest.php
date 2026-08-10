<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\SalesWeeklyMetricsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesWeeklyMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_period_is_monday_through_sunday_and_fifth_partial_month_week_crosses_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30 12:00:00', config('app.timezone')));
        [, $project, $sales] = $this->context();
        $this->lead($sales, $project, ['lead_date' => '2026-07-26']);
        $this->lead($sales, $project, ['lead_date' => '2026-07-27']);
        $this->lead($sales, $project, ['lead_date' => '2026-08-02']);
        $this->lead($sales, $project, ['lead_date' => '2026-08-03']);

        $service = app(SalesWeeklyMetricsService::class);
        $period = $service->period();

        $this->assertSame('2026-07-27 00:00:00', $period['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-02 23:59:59', $period['end']->format('Y-m-d H:i:s'));
        $this->assertSame(2, $service->metrics($sales, $period)['lead_new']);
        $custom = $service->period(null, '2026-07-29', '2026-08-01');
        $this->assertSame('2026-07-29 00:00:00', $custom['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-01 23:59:59', $custom['end']->format('Y-m-d H:i:s'));
    }

    public function test_each_stage_uses_its_own_timestamp_and_old_lead_reaching_utj_counts(): void
    {
        [, $project, $sales] = $this->context();
        $lead = $this->lead($sales, $project, ['lead_date' => '2026-06-01']);
        $lead->update([
            'contacted_at' => '2026-06-02 09:00:00',
            'met_at' => '2026-07-20 10:00:00',
            'utj_at' => '2026-07-22 11:00:00',
        ]);

        $metrics = app(SalesWeeklyMetricsService::class)->metrics(
            $sales,
            app(SalesWeeklyMetricsService::class)->period('2026-07-23'),
        );

        $this->assertSame(0, $metrics['lead_new']);
        $this->assertSame(0, $metrics['contacted']);
        $this->assertSame(1, $metrics['met']);
        $this->assertSame(1, $metrics['utj']);
        $this->assertNull($metrics['conversions']['lead_contacted']);
    }

    public function test_zero_denominator_is_null_and_report_displays_dash(): void
    {
        [$branch, , $sales] = $this->context();
        $manager = $this->user('manager', $branch);
        $period = app(SalesWeeklyMetricsService::class)->period('2026-07-23');

        $this->assertNull(app(SalesWeeklyMetricsService::class)->metrics($sales, $period)['conversions']['documents_akad']);
        $this->actingAs($manager)->get(route('sales-pocketbook.index', ['tab' => 'report', 'period_type' => 'week', 'week' => '2026-07-23']))
            ->assertOk()->assertSee('Konversi: —');
    }

    public function test_app_timezone_boundary_includes_sunday_end_and_excludes_next_monday(): void
    {
        [, $project, $sales] = $this->context();
        $this->lead($sales, $project, ['contacted_at' => Carbon::parse('2026-07-26 23:59:59', 'Asia/Jakarta')]);
        $this->lead($sales, $project, ['contacted_at' => Carbon::parse('2026-07-27 00:00:00', 'Asia/Jakarta')]);

        $metrics = app(SalesWeeklyMetricsService::class)->metrics($sales, app(SalesWeeklyMetricsService::class)->period('2026-07-20'));

        $this->assertSame(1, $metrics['contacted']);
    }

    public function test_branch_project_sales_filters_and_manager_pusat_superadmin_scopes_are_enforced(): void
    {
        [$branch, $project, $sales] = $this->context();
        $otherBranch = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $otherProject = LeadMaster::create(['branch_id' => $otherBranch->id, 'project_name' => 'Pati Project', 'is_active' => true]);
        $otherSales = $this->sales($otherBranch, $otherProject, 'Pati Sales');
        $this->lead($sales, $project, ['lead_date' => '2026-07-21']);
        $this->lead($otherSales, $otherProject, ['lead_date' => '2026-07-21']);
        $manager = $this->user('manager', $branch);
        $pusat = $this->user('pusat');
        $super = $this->user('superadmin');
        $period = app(SalesWeeklyMetricsService::class)->period('2026-07-21');
        $service = app(SalesWeeklyMetricsService::class);

        $this->assertSame(1, $service->metrics($manager, $period)['lead_new']);
        $this->assertSame(2, $service->metrics($pusat, $period)['lead_new']);
        $this->assertSame(2, $service->metrics($super, $period)['lead_new']);
        $this->assertSame(1, $service->metrics($pusat, $period, ['branch_id' => $branch->id, 'project_id' => $project->id, 'sales_user_id' => $sales->id])['lead_new']);
        $this->actingAs($manager)->get(route('sales-pocketbook.index', ['tab' => 'report', 'branch_id' => $otherBranch->id]))->assertForbidden();
    }

    public function test_monitoring_rows_are_sortable_and_stage_and_agenda_drilldowns_use_same_period(): void
    {
        [$branch, $project, $sales] = $this->context('Zulu Sales');
        $alpha = $this->sales($branch, $project, 'Alpha Sales');
        $lead = $this->lead($sales, $project, ['lead_date' => '2026-06-01', 'customer_name' => 'Old UTJ Lead', 'utj_at' => '2026-07-22 09:00:00']);
        $this->agenda($sales, $project, ['status' => 'done', 'completed_at' => '2026-07-23 12:00:00', 'activity_result' => 'Selesai']);
        $manager = $this->user('manager', $branch);

        $response = $this->actingAs($manager)->get(route('sales-pocketbook.index', ['tab' => 'report', 'period_type' => 'week', 'week' => '2026-07-22', 'sort' => 'sales', 'direction' => 'desc']));
        $response->assertOk()->assertViewHas('reportRows', fn ($rows) => $rows->pluck('sales.name')->values()->all() === ['Zulu Sales', 'Alpha Sales']
            && $rows->first()['utj'] === 1
            && $rows->first()['agenda_completed'] === 1);
        $response->assertSee('report_metric=utj', false)->assertSee('report_agenda_completed=1', false);
        $this->actingAs($manager)->get(route('sales-pocketbook.index', ['tab' => 'report', 'sort' => 'customer_name']))->assertSessionHasErrors('sort');

        $this->actingAs($manager)->get(route('sales-pocketbook.index', [
            'tab' => 'leads', 'report_metric' => 'utj', 'period_type' => 'custom', 'date_from' => '2026-07-20', 'date_to' => '2026-07-26',
            'branch_id' => $branch->id, 'project_id' => $project->id, 'sales_user_id' => $sales->id,
        ]))->assertOk()->assertSee($lead->customer_name);
        $this->actingAs($manager)->get(route('sales-pocketbook.index', [
            'tab' => 'agenda', 'report_agenda_completed' => 1, 'period_type' => 'custom', 'date_from' => '2026-07-20', 'date_to' => '2026-07-26',
            'branch_id' => $branch->id, 'project_id' => $project->id, 'sales_user_id' => $sales->id,
        ]))->assertOk()->assertSee('Agenda Test');
    }

    public function test_sales_shared_landing_is_agenda_only(): void
    {
        [, , $sales] = $this->context();

        $this->actingAs($sales)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($sales)->get('/')->assertRedirect(route('sales-pocketbook.index'));
        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('Agenda Saya')
            ->assertDontSee('Input Lead')
            ->assertDontSee('Ringkasan periode')
            ->assertDontSee('Sinkronisasi');
    }

    public function test_non_sales_dashboard_keeps_existing_actions_and_global_roles_get_monitoring_only(): void
    {
        [$branch] = $this->context();
        $admin = $this->user('admin', $branch);
        $pusat = $this->user('pusat');

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('Aksi Cepat')->assertDontSee('Buku Saku Minggu Ini');
        $this->actingAs($pusat)->get(route('dashboard'))->assertOk()->assertSee('Monitoring Buku Saku')->assertDontSee('+ Input Lead Hari Ini')->assertDontSee('+ Isi Agenda / Hasil');
    }

    private function context(string $salesName = 'Solo Sales'): array
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Solo Project', 'is_active' => true]);

        return [$branch, $project, $this->sales($branch, $project, $salesName)];
    }

    private function sales(Branch $branch, LeadMaster $project, string $name): User
    {
        $sales = $this->user('sales', $branch, $name);
        $sales->assignedProjects()->attach($project, ['is_primary' => true]);

        return $sales;
    }

    private function user(string $roleSlug, ?Branch $branch = null, ?string $name = null): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => ucfirst($roleSlug), 'is_superadmin' => $roleSlug === 'superadmin']);

        return User::factory()->create(['name' => $name ?? ucfirst($roleSlug), 'role_id' => $role->id, 'branch_id' => $branch?->id, 'password_changed_at' => now()]);
    }

    private function lead(User $sales, LeadMaster $project, array $overrides = []): SalesLead
    {
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);
        $lead = SalesLead::create(array_merge([
            'branch_id' => $project->branch_id, 'project_id' => $project->id, 'sales_user_id' => $sales->id,
            'lead_date' => '2026-07-20', 'customer_name' => 'Lead Test', 'created_by' => $sales->id,
        ], $overrides));
        if ($createdAt) {
            $lead->timestamps = false;
            $lead->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
        }

        return $lead;
    }

    private function agenda(User $sales, LeadMaster $project, array $overrides = []): ContentItem
    {
        return ContentItem::create(array_merge([
            'branch_id' => $project->branch_id, 'project_name' => $project->project_name, 'sales_project_id' => $project->id,
            'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'visibility' => 'personal',
            'title' => 'Agenda Test', 'scheduled_date' => '2026-07-23', 'status' => 'planned',
            'owner_user_id' => $sales->id, 'created_by' => $sales->id,
        ], $overrides));
    }
}
