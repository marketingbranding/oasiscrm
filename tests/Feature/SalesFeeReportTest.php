<?php

namespace Tests\Feature;

use App\Enums\SalesLeadStatus;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\GoogleSheetsApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class SalesFeeReportTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Branch $branch;

    private Branch $foreignBranch;

    private LeadMaster $project;

    private LeadMaster $secondProject;

    private LeadMaster $foreignProject;

    private User $sales;

    private User $zeroSales;

    private User $foreignSales;

    private User $coordinator;

    private User $historicalCoordinator;

    private User $foreignCoordinator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.local_only' => false]);
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldNotReceive(Mockery::any());
        $this->app->instance(GoogleSheetsApiService::class, $google);

        $this->branch = Branch::create(['name' => 'Cabang Sendiri', 'code' => 'OWN', 'is_active' => true]);
        $this->foreignBranch = Branch::create(['name' => 'Cabang Asing', 'code' => 'FOR', 'is_active' => true]);
        $this->project = $this->project($this->branch, 'Proyek Utama');
        $this->secondProject = $this->project($this->branch, 'Proyek Kedua');
        $this->foreignProject = $this->project($this->foreignBranch, 'Proyek Asing');
        $this->actor = $this->user('admin', $this->branch, 'Admin Legacy');
        $this->actor->branches()->updateExistingPivot($this->branch, $this->branchMembership());
        $this->sales = $this->user('sales', $this->branch, 'Sales Sendiri');
        $this->zeroSales = $this->user('sales', $this->branch, 'Sales Kosong');
        $this->foreignSales = $this->user('sales', $this->foreignBranch, 'Sales Asing');
        $this->coordinator = $this->user('sales_coordinator', $this->branch, 'Koordinator Aktif');
        $this->historicalCoordinator = $this->user('sales_coordinator', $this->branch, 'Koordinator Historis');
        $this->foreignCoordinator = $this->user('sales_coordinator', $this->foreignBranch, 'Koordinator Asing');

        $this->assign($this->sales, $this->project);
        $this->assign($this->sales, $this->secondProject);
        $this->assign($this->zeroSales, $this->project);
        $this->assign($this->foreignSales, $this->foreignProject);
        $this->coordinator->currentCoordinatorSales()->attach($this->sales, [
            'is_active' => true, 'started_at' => today()->subMonth(), 'ended_at' => today()->addMonth(),
        ]);
        $this->historicalCoordinator->currentCoordinatorSales()->attach($this->sales, [
            'is_active' => true, 'started_at' => today()->subYear(), 'ended_at' => today()->subMonth(),
        ]);
    }

    public function test_index_is_available_in_navigation_and_scoped_to_primary_branch(): void
    {
        $response = $this->actingAs($this->actor)->get($this->indexUrl());

        $response->assertOk()
            ->assertSee('Laporan Fee Sales')
            ->assertSee('Sales Sendiri')
            ->assertSee('Sales Kosong')
            ->assertSee('Proyek Utama')
            ->assertSee('Proyek Kedua')
            ->assertSee('Koordinator Aktif')
            ->assertDontSee('Cabang Asing')
            ->assertDontSee('Sales Asing')
            ->assertDontSee('Proyek Asing')
            ->assertDontSee('Koordinator Asing')
            ->assertViewHas('rows', fn ($rows) => $rows->where('coordinator_name', 'Koordinator Historis')->isEmpty());
    }

    public function test_foreign_filters_and_detail_routes_are_forbidden(): void
    {
        foreach ([
            ['project_id' => $this->foreignProject->id],
            ['sales_user_id' => $this->foreignSales->id],
            ['coordinator_id' => $this->foreignCoordinator->id],
        ] as $filter) {
            $this->actingAs($this->actor)->get(route('sales-fee-reports.index', $filter + $this->period()))->assertForbidden();
        }

        $this->actingAs($this->actor)->get(route('sales-fee-reports.show', [$this->foreignSales, $this->foreignProject] + $this->period()))->assertForbidden();
        $this->actingAs($this->actor)->get(route('sales-fee-reports.print', [$this->foreignSales, $this->foreignProject] + $this->period()))->assertForbidden();
        $this->actingAs($this->actor)->get(route('sales-fee-reports.show', [$this->sales, $this->foreignProject] + $this->period()))->assertForbidden();
    }

    public function test_current_coordinator_is_shown_historical_is_excluded_and_unassigned_sales_uses_dash(): void
    {
        $response = $this->actingAs($this->actor)->get($this->indexUrl());

        $response->assertOk()->assertSee('Koordinator Aktif')
            ->assertViewHas('rows', fn ($rows) => $rows->where('coordinator_name', 'Koordinator Historis')->isEmpty());
        $this->actingAs($this->actor)
            ->get(route('sales-fee-reports.show', [$this->zeroSales, $this->project] + $this->period()))
            ->assertOk()->assertSeeTextInOrder(['Koordinator', '-']);
    }

    public function test_agenda_and_lead_totals_use_exact_period_project_and_sales_agenda_contract(): void
    {
        $this->agenda($this->sales, $this->project, 'Agenda Selesai', '2026-08-05', 'done');
        $this->agenda($this->sales, $this->project, 'Agenda Rencana', '2026-08-06', 'planned');
        $this->agenda($this->sales, $this->project, 'Agenda Di Luar', '2026-07-31', 'done');
        $this->agenda($this->sales, $this->secondProject, 'Agenda Proyek Kedua', '2026-08-05', 'done');
        $this->agenda($this->sales, $this->project, 'Agenda Non Sales', '2026-08-05', 'done', null);
        $this->lead($this->sales, $this->project, 'Lead Dalam Satu', '2026-08-03');
        $this->lead($this->sales, $this->project, 'Lead Dalam Dua', '2026-08-10');
        $this->lead($this->sales, $this->project, 'Lead Di Luar', '2026-07-31');
        $this->lead($this->sales, $this->secondProject, 'Lead Proyek Kedua', '2026-08-03');

        $response = $this->actingAs($this->actor)->get(route('sales-fee-reports.show', [$this->sales, $this->project] + $this->period()));

        $response->assertOk()
            ->assertViewHas('metrics', fn (array $metrics) => $metrics['total_agenda'] === 2 && $metrics['agenda_done'] === 1 && $metrics['total_lead'] === 2)
            ->assertSee('Agenda Selesai')->assertSee('Agenda Rencana')->assertSee('Lead Dalam Satu')->assertSee('Lead Dalam Dua')
            ->assertDontSee('Agenda Di Luar')->assertDontSee('Agenda Proyek Kedua')->assertDontSee('Agenda Non Sales')
            ->assertDontSee('Lead Di Luar')->assertDontSee('Lead Proyek Kedua');
    }

    public function test_lifecycle_counts_first_status_in_period_once_and_excludes_out_of_period(): void
    {
        $lead = $this->lead($this->sales, $this->project, 'Lead Lifecycle', '2026-07-01');
        $secondLead = $this->lead($this->sales, $this->project, 'Lead Lifecycle Dua', '2026-07-01');
        $this->history($lead, SalesLeadStatus::FaceToFace, '2026-08-03 09:00:00', 'one');
        $this->history($lead, SalesLeadStatus::FaceToFace, '2026-08-04 09:00:00', 'duplicate');
        $this->history($lead, SalesLeadStatus::SiteVisit, '2026-08-05 09:00:00', 'visit');
        $this->history($lead, SalesLeadStatus::Utj, '2026-08-06 09:00:00', 'utj');
        $this->history($secondLead, SalesLeadStatus::FaceToFace, '2026-07-30 09:00:00', 'old-first');
        $this->history($secondLead, SalesLeadStatus::FaceToFace, '2026-08-07 09:00:00', 'new-duplicate');
        $this->history($secondLead, SalesLeadStatus::SiteVisit, '2026-09-01 09:00:00', 'future');

        $this->actingAs($this->actor)
            ->get(route('sales-fee-reports.show', [$this->sales, $this->project] + $this->period()))
            ->assertOk()
            ->assertViewHas('metrics', fn (array $metrics) => $metrics['face_to_face'] === 1 && $metrics['site_visit'] === 1 && $metrics['utj'] === 1);
    }

    public function test_authoritative_lifecycle_dates_count_backdated_progression_even_after_current_status_advances(): void
    {
        $lead = $this->lead($this->sales, $this->project, 'Lead Backdated UTJ', '2026-08-10');
        $lead->update([
            'met_at' => '2026-08-11 09:00:00',
            'surveyed_at' => '2026-08-12 09:00:00',
            'utj_at' => '2026-08-13 09:00:00',
            'current_status' => SalesLeadStatus::Akad,
        ]);
        $outside = $this->lead($this->sales, $this->project, 'Lead UTJ Outside', '2026-08-10');
        $outside->update(['utj_at' => '2026-09-01 09:00:00']);

        $response = $this->actingAs($this->actor)->get(route('sales-fee-reports.show', [$this->sales, $this->project] + $this->period()));

        $response->assertViewHas('metrics', fn (array $metrics) => $metrics['face_to_face'] === 1 && $metrics['site_visit'] === 1 && $metrics['utj'] === 1);
    }

    public function test_duplicate_authoritative_events_count_once_and_legacy_history_is_fallback(): void
    {
        $lead = $this->lead($this->sales, $this->project, 'Lead Duplicate UTJ', '2026-08-10');
        $lead->update(['utj_at' => '2026-08-14 10:00:00']);
        $this->history($lead, SalesLeadStatus::Utj, '2026-08-12 09:00:00', 'old-history');
        $legacy = $this->lead($this->sales, $this->project, 'Lead Legacy UTJ', '2026-08-10');
        $this->history($legacy, SalesLeadStatus::Utj, '2026-08-13 09:00:00', 'legacy-history');
        $outside = $this->lead($this->sales, $this->project, 'Lead Legacy Outside', '2026-08-10');
        $this->history($outside, SalesLeadStatus::Utj, '2026-09-01 09:00:00', 'outside-history');

        $response = $this->actingAs($this->actor)->get(route('sales-fee-reports.show', [$this->sales, $this->project] + $this->period()));

        $response->assertViewHas('metrics', fn (array $metrics) => $metrics['utj'] === 2);
    }

    public function test_print_and_detail_share_authoritative_lifecycle_metrics(): void
    {
        $lead = $this->lead($this->sales, $this->project, 'Lead Print Metric', '2026-08-10');
        $lead->update(['utj_at' => '2026-08-12 09:00:00', 'current_status' => SalesLeadStatus::Akad]);

        $detail = $this->actingAs($this->actor)->get(route('sales-fee-reports.show', [$this->sales, $this->project] + $this->period()));
        $print = $this->actingAs($this->actor)->get(route('sales-fee-reports.print', [$this->sales, $this->project] + $this->period()));

        $detail->assertViewHas('metrics', fn (array $metrics) => $metrics['utj'] === 1);
        $print->assertSee('UTJ')->assertSee('1');
    }

    public function test_lifecycle_metrics_keep_scope_to_sales_project_and_branch(): void
    {
        $this->lead($this->sales, $this->project, 'Scoped Lead', '2026-08-10')->update(['utj_at' => '2026-08-12 09:00:00']);
        $foreign = $this->lead($this->foreignSales, $this->foreignProject, 'Foreign Lead', '2026-08-10');
        $foreign->update(['utj_at' => '2026-08-12 09:00:00']);

        $response = $this->actingAs($this->actor)->get(route('sales-fee-reports.show', [$this->sales, $this->project] + $this->period()));

        $response->assertViewHas('metrics', fn (array $metrics) => $metrics['utj'] === 1);
    }

    public function test_multi_project_rows_are_separate_counts_are_not_duplicated_and_zero_activity_row_remains(): void
    {
        $this->agenda($this->sales, $this->project, 'P1 Agenda', '2026-08-05', 'done');
        $this->agenda($this->sales, $this->secondProject, 'P2 Agenda', '2026-08-05', 'planned');
        $this->lead($this->sales, $this->project, 'P1 Lead', '2026-08-05');
        $this->lead($this->sales, $this->secondProject, 'P2 Lead', '2026-08-05');

        $response = $this->actingAs($this->actor)->get($this->indexUrl());

        $response->assertOk()->assertViewHas('rows', function ($rows) {
            $salesRows = $rows->where('user_id', $this->sales->id);
            $zero = $rows->first(fn ($row) => $row->user_id === $this->zeroSales->id && $row->project_id === $this->project->id);

            return $salesRows->count() === 2
                && $salesRows->pluck('project_id')->unique()->count() === 2
                && $salesRows->sum('agenda_total') === 2
                && $salesRows->sum('lead_total') === 2
                && $zero && $zero->agenda_total === 0 && $zero->lead_total === 0;
        });
    }

    public function test_detail_and_print_are_read_only_with_expected_tables_and_metadata(): void
    {
        $agendaWithResult = $this->agenda($this->sales, $this->project, 'Agenda Dengan Hasil', '2026-08-05', 'done');
        $agendaWithResult->update(['activity_result' => 'Konsumen setuju survei', 'sales_activity_category' => 'Follow Up', 'location' => 'Kantor']);
        $this->agenda($this->sales, $this->project, 'Agenda Tanpa Hasil', '2026-08-06', 'planned');
        $this->lead($this->sales, $this->project, 'Konsumen Lengkap', '2026-08-05')->update(['platform' => 'WhatsApp', 'campaign_name' => 'Promo Agustus']);
        $this->lead($this->sales, $this->project, 'Konsumen Platform', '2026-08-06')->update(['platform' => 'Instagram']);
        $this->lead($this->sales, $this->project, 'Konsumen Kampanye', '2026-08-07')->update(['campaign_name' => 'Open House']);

        foreach (['sales-fee-reports.show', 'sales-fee-reports.print'] as $route) {
            $response = $this->actingAs($this->actor)->get(route($route, [$this->sales, $this->project] + $this->period()));
            $response->assertOk()
                ->assertSee('LAPORAN AKTIVITAS SALES')->assertSee('Sales Sendiri')->assertSee('Koordinator Aktif')
                ->assertSee('Cabang Sendiri')->assertSee('Proyek Utama')->assertSee('01/08/2026 - 31/08/2026')
                ->assertSee('DETAIL AGENDA')->assertSee('DETAIL LEAD')->assertSee('Agenda Dengan Hasil')->assertSee('Konsumen Lengkap')
                ->assertDontSee('Edit')->assertDontSee('Push ke Google')->assertDontSee('Sync Lead');
        }

        $response = $this->actingAs($this->actor)
            ->get(route('sales-fee-reports.print', [$this->sales, $this->project] + $this->period()));
        $content = $response->getContent();
        $resultRow = str($content)->between('<div class="agenda-title">Agenda Dengan Hasil</div>', '</tr>')->toString();
        $blankRow = str($content)->between('<div class="agenda-title">Agenda Tanpa Hasil</div>', '</tr>')->toString();

        $response->assertSee('class="screen-toolbar screen-only"', false)
            ->assertSee('class="print-sheet"', false)
            ->assertSee('@page { size: A4 portrait; margin: 0; }', false)
            ->assertSee('.print-sheet { width: 210mm; min-height: auto; margin: 0; padding: 12mm; box-shadow: none; }', false)
            ->assertSeeTextInOrder(['Tanggal', 'Kategori', 'Agenda / Hasil', 'Lokasi', 'Status'])
            ->assertSeeTextInOrder(['Tanggal', 'Konsumen', 'Sumber', 'Kanal / Aktivitas', 'Status'])
            ->assertSee('Hasil: Konsumen setuju survei')->assertSee('WhatsApp / Promo Agustus')
            ->assertSee('Konsumen Platform')->assertSee('Instagram')->assertSee('Konsumen Kampanye')->assertSee('Open House')
            ->assertSee('Total Agenda')->assertSee('Agenda Selesai')->assertSee('Total Lead')
            ->assertSee('Tatap Muka')->assertSee('Cek Lokasi')->assertSee('UTJ')
            ->assertDontSee('Tanggal Lead')->assertDontSee('Nama Konsumen')->assertDontSee('Sumber Lead')
            ->assertDontSee('Aktivitas Lead')->assertDontSee('<th>Proyek</th>', false)
            ->assertDontSee(' / Instagram')->assertDontSee('Instagram / ')
            ->assertDontSee(' / Open House')->assertDontSee('Open House / ');
        $this->assertStringContainsString('Hasil: Konsumen setuju survei', $resultRow);
        $this->assertStringNotContainsString('Hasil:', $blankRow);
    }

    public function test_only_primary_legacy_admin_role_is_allowed(): void
    {
        $allowed = $this->user('admin', $this->branch, 'Admin Allowed');
        $allowed->branches()->updateExistingPivot($this->branch, $this->branchMembership());
        $this->actingAs($allowed)->get($this->indexUrl())->assertOk();

        foreach (['branch_manager', 'manager', 'pusat', 'superadmin', 'supervisor', 'sales_coordinator', 'sales', 'staff'] as $slug) {
            $user = $this->user($slug, $this->branch, 'Denied '.$slug);
            $user->branches()->updateExistingPivot($this->branch, $this->branchMembership());
            $this->actingAs($user)->get($this->indexUrl())->assertForbidden();
        }

        $supplemental = $this->user('sales', $this->branch, 'Supplemental Admin');
        $supplemental->branches()->updateExistingPivot($this->branch, $this->branchMembership());
        $supplemental->roles()->attach(Role::query()->where('slug', 'admin')->firstOrFail());
        $this->actingAs($supplemental)->get($this->indexUrl())->assertForbidden();
    }

    public function test_dates_are_required_valid_and_ordered(): void
    {
        foreach ([
            ['date_from' => 'invalid', 'date_to' => '2026-08-31'],
            ['date_from' => '2026-08-01', 'date_to' => 'invalid'],
            ['date_from' => '2026-08-31', 'date_to' => '2026-08-01'],
        ] as $dates) {
            $this->actingAs($this->actor)->get(route('sales-fee-reports.index', $dates))->assertSessionHasErrors();
        }
    }

    public function test_get_requests_do_not_mutate_report_records(): void
    {
        $agenda = $this->agenda($this->sales, $this->project, 'Immutable Agenda', '2026-08-05', 'done');
        $lead = $this->lead($this->sales, $this->project, 'Immutable Lead', '2026-08-05');
        $counts = ['content_items' => ContentItem::count(), 'sales_leads' => SalesLead::count(), 'histories' => DB::table('sales_lead_status_histories')->count()];
        $timestamps = [$agenda->updated_at?->toISOString(), $lead->updated_at?->toISOString()];

        $this->actingAs($this->actor)->get($this->indexUrl())->assertOk();
        $this->actingAs($this->actor)->get(route('sales-fee-reports.show', [$this->sales, $this->project] + $this->period()))->assertOk();
        $this->actingAs($this->actor)->get(route('sales-fee-reports.print', [$this->sales, $this->project] + $this->period()))->assertOk();

        $this->assertSame($counts, ['content_items' => ContentItem::count(), 'sales_leads' => SalesLead::count(), 'histories' => DB::table('sales_lead_status_histories')->count()]);
        $this->assertSame($timestamps, [$agenda->fresh()->updated_at?->toISOString(), $lead->fresh()->updated_at?->toISOString()]);
    }

    private function period(): array
    {
        return ['date_from' => '2026-08-01', 'date_to' => '2026-08-31'];
    }

    private function indexUrl(): string
    {
        return route('sales-fee-reports.index', $this->period());
    }

    private function project(Branch $branch, string $name): LeadMaster
    {
        return LeadMaster::create(['branch_id' => $branch->id, 'project_name' => $name, 'is_active' => true]);
    }

    private function user(string $role, Branch $branch, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
    }

    private function branchMembership(): array
    {
        return ['membership_role' => 'primary', 'can_view' => true, 'can_edit' => false, 'can_sync' => false, 'can_manage_members' => false];
    }

    private function assign(User $sales, LeadMaster $project): void
    {
        $sales->branches()->syncWithoutDetaching([$project->branch_id => $this->branchMembership()]);
        $sales->assignedProjects()->attach($project, [
            'is_primary' => false, 'is_active' => true,
            'assignment_start_date' => today()->subMonth(), 'assignment_end_date' => today()->addMonth(),
        ]);
    }

    private function agenda(User $sales, LeadMaster $project, string $title, string $date, string $status, ?string $agendaType = ContentItem::SALES_AGENDA_TYPE): ContentItem
    {
        return ContentItem::create([
            'branch_id' => $project->branch_id, 'project_name' => $project->project_name,
            'item_type' => 'agenda', 'agenda_type' => $agendaType, 'visibility' => 'personal',
            'title' => $title, 'scheduled_date' => $date, 'status' => $status,
            'owner_user_id' => $sales->id, 'sales_project_id' => $project->id, 'created_by' => $sales->id,
        ]);
    }

    private function lead(User $sales, LeadMaster $project, string $name, string $date): SalesLead
    {
        return SalesLead::create([
            'branch_id' => $project->branch_id, 'project_id' => $project->id, 'sales_user_id' => $sales->id,
            'lead_date' => $date, 'customer_name' => $name, 'source' => 'Referensi',
            'current_status' => SalesLeadStatus::Discussion, 'created_by' => $sales->id,
        ]);
    }

    private function history(SalesLead $lead, SalesLeadStatus $status, string $changedAt, string $sourceId): void
    {
        DB::table('sales_lead_status_histories')->insert([
            'sales_lead_id' => $lead->id, 'branch_id' => $lead->branch_id, 'actor_id' => $this->actor->id,
            'status' => $status->value, 'source' => 'test', 'source_id' => $sourceId,
            'changed_at' => $changedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
