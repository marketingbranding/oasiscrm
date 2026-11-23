<?php

namespace Tests\Feature;

use App\Enums\SalesLeadStatus;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\OrganizationAssignment;
use App\Models\Role;
use App\Models\SalesAgendaEvidence;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\SalesLeadSiteVisit;
use App\Models\SalesLeadStatusHistory;
use App\Models\SalesLeadUnownedQueue;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AppSettingResolver;
use App\Services\CoordinatorAgendaReminderService;
use App\Services\CoordinatorSalesMonitoringService;
use App\Services\LeadAttentionService;
use App\Services\SalesMonitoringProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

    public function test_br51_direct_coordinator_monitoring_url_rejects_forged_sales_filter(): void
    {
        $outsider = $this->user('sales', 'Bukan Tim', $this->branch);

        $this->actingAs($this->coordinator)
            ->get(route('sales-pocketbook.index', [
                'tab' => 'today',
                'view' => 'detail',
                'sales_id' => $outsider->id,
            ]))
            ->assertForbidden();
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

    public function test_weekly_incomplete_agendas_include_only_prior_dates_missing_result_or_evidence(): void
    {
        $resultMissing = $this->agenda($this->sales, 'Hasil belum ada', $this->branch, $this->project, '2026-08-10');
        $evidenceMissing = $this->agenda($this->sales, 'Bukti belum ada', $this->branch, $this->project, '2026-08-10');
        $this->agenda($this->sales, 'Hari ini', $this->branch, $this->project, '2026-08-11');
        $this->agenda($this->sales, 'Minggu lalu', $this->branch, $this->project, '2026-08-08');
        $complete = $this->agenda($this->sales, 'Lengkap', $this->branch, $this->project, '2026-08-10');
        $complete->forceFill(['activity_result' => 'Sudah dikunjungi'])->save();
        SalesAgendaEvidence::create([
            'content_item_id' => $complete->id,
            'original_name' => 'bukti.jpg',
            'mime_type' => 'image/jpeg',
            'width' => 100,
            'height' => 100,
            'size_bytes' => 100,
            'sha256' => str_repeat('a', 64),
        ]);
        $this->agenda($this->outsideSales, 'Sales luar', $this->outsideBranch, $this->outsideProject, '2026-08-10');

        $groups = app(CoordinatorSalesMonitoringService::class)->weeklyIncompleteAgendas($this->coordinator);

        $this->assertSame([$this->sales->id], $groups->keys()->all());
        $this->assertSame([$resultMissing->id, $evidenceMissing->id], $groups->first()->pluck('id')->all());
    }

    public function test_today_workspace_uses_active_team_and_counts_submitted_plans_and_completed_agendas(): void
    {
        $done = $this->agenda($this->sales, 'Selesai', $this->branch, $this->project, '2026-08-11');
        $done->forceFill(['status' => 'done', 'activity_result' => 'Selesai'])->save();
        $this->agenda($this->outsideSales, 'Agenda luar tim', $this->outsideBranch, $this->outsideProject, '2026-08-11');

        $workspace = app(CoordinatorSalesMonitoringService::class)->todayWorkspace($this->coordinator);

        $this->assertSame(1, $workspace['sales_count']);
        $this->assertSame(0, $workspace['submitted_count']);
        $this->assertSame(1, $workspace['completed_agenda_count']);
        $this->assertSame('Sales Tim', $workspace['cards']->first()['sales']->name);
    }

    public function test_coordinator_agenda_reminders_require_confirmation_within_twenty_four_hours(): void
    {
        $service = app(CoordinatorAgendaReminderService::class);
        $secondSales = $this->user('sales', 'Sales Kedua', $this->branch);
        $secondSales->assignedProjects()->attach($this->project, ['is_primary' => true, 'is_active' => true]);
        SalesCoordinatorSales::create(['coordinator_user_id' => $this->coordinator->id, 'sales_user_id' => $secondSales->id]);

        $firstSend = $service->send($this->coordinator, [$this->sales->id]);
        $repeatSend = $service->send($this->coordinator, [$this->sales->id, $secondSales->id]);

        $this->assertSame(1, $firstSend['sent']);
        $this->assertSame(1, $repeatSend['sent']);
        $this->assertSame([$this->sales->id], $repeatSend['needs_confirmation']);
        $this->assertSame(1, UserNotification::where('user_id', $this->sales->id)->count());
        $this->assertSame(1, UserNotification::where('user_id', $secondSales->id)->count());
        $this->assertSame($this->coordinator->id, UserNotification::where('user_id', $this->sales->id)->value('actor_user_id'));

        $confirmed = $service->send($this->coordinator, [$this->sales->id], true);

        $this->assertSame(1, $confirmed['sent']);
        $this->assertSame(2, UserNotification::where('user_id', $this->sales->id)->count());
        $this->assertSame(1, UserNotification::where('user_id', $secondSales->id)->count());

        $this->expectException(HttpException::class);
        $service->send($this->coordinator, [999999]);
    }

    public function test_sales_detail_returns_week_strip_and_selected_day_agendas_only_for_team_members(): void
    {
        $this->agenda($this->sales, 'Monday agenda', $this->branch, $this->project, '2026-08-10');
        $today = $this->agenda($this->sales, 'Tuesday agenda', $this->branch, $this->project, '2026-08-11');
        $detail = app(CoordinatorSalesMonitoringService::class)->salesDetail($this->coordinator, $this->sales->id);

        $this->assertCount(7, $detail['week']);
        $this->assertSame([$today->id], $detail['agendas']->pluck('id')->all());
        $this->assertSame('incomplete', $detail['week'][0]['state']);

        $this->expectException(HttpException::class);
        app(CoordinatorSalesMonitoringService::class)->salesDetail($this->coordinator, $this->outsideSales->id);
    }

    public function test_br51_flag_on_coordinator_http_screen_and_export_project_distant_details(): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => 'org_hierarchical_monitoring'], [
            'value' => json_encode(true, JSON_THROW_ON_ERROR), 'group' => 'feature_flags', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(AppSettingResolver::class)->forget('org_hierarchical_monitoring');
        config(['organization.graph_mode' => 'graph']);

        $distantSupervisor = $this->user('supervisor', 'Supervisor Jarak', $this->branch);
        $distantCoordinator = $this->user('sales_coordinator', 'Koordinator Jarak', $this->branch, $distantSupervisor);
        $distantSales = $this->user('sales', 'Sales Jarak', $this->branch);
        $distantSales->assignedProjects()->attach($this->project, ['is_primary' => true, 'is_active' => true]);
        SalesCoordinatorSales::create(['coordinator_user_id' => $distantCoordinator->id, 'sales_user_id' => $distantSales->id]);
        OrganizationAssignment::create([
            'user_id' => $distantCoordinator->id, 'parent_user_id' => $this->coordinator->id, 'relationship_type' => OrganizationAssignment::REPORTS_TO,
            'branch_id' => $this->branch->id, 'started_at' => today(), 'is_active' => true, 'is_primary' => true,
        ]);
        OrganizationAssignment::create([
            'user_id' => $distantSales->id, 'parent_user_id' => $distantCoordinator->id, 'relationship_type' => OrganizationAssignment::REPORTS_TO,
            'branch_id' => $this->branch->id, 'started_at' => today(), 'is_active' => true, 'is_primary' => true,
        ]);
        OrganizationAssignment::create([
            'user_id' => $distantSales->id, 'parent_user_id' => $distantCoordinator->id, 'relationship_type' => OrganizationAssignment::SALES_TEAM_MEMBER_OF,
            'branch_id' => $this->branch->id, 'started_at' => today(), 'is_active' => true, 'is_primary' => true,
        ]);
        $lead = $this->lead($distantSales, 'Lead Koordinator Jauh', '2026-08-11', $this->project, $this->branch);
        $lead->update(['phone' => '089999888777', 'notes' => 'Catatan koordinator rahasia']);
        $agenda = $this->agenda($distantSales, 'Agenda Koordinator Rahasia', $this->branch, $this->project, '2026-08-10');
        $agenda->update(['status' => 'done', 'location' => 'Lokasi Koordinator Rahasia', 'activity_result' => 'Hasil Koordinator Rahasia']);

        $screen = $this->actingAs($this->coordinator)->get(route('sales-pocketbook.index', ['tab' => 'lead', 'period' => 'today']));
        $screen->assertOk()->assertSee('Lead Koordinator Jauh')
            ->assertDontSee('089999888777')->assertDontSee('Catatan koordinator rahasia');

        $agendaScreen = $this->actingAs($this->coordinator)->get(route('sales-pocketbook.index', [
            'tab' => 'agenda', 'period' => 'custom', 'date_from' => '2026-08-10', 'date_to' => '2026-08-10',
        ]));
        $agendaScreen->assertOk()->assertDontSee('Agenda Koordinator Rahasia')
            ->assertDontSee('Lokasi Koordinator Rahasia')->assertDontSee('Hasil Koordinator Rahasia')
            ->assertSee('Ringkasan kegiatan');

        $response = $this->actingAs($this->coordinator)->get(route('coordinator-leads.export', ['period' => 'today']));
        $response->assertOk();
        $workbook = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $values = collect($workbook->getActiveSheet()->toArray())->flatten()->implode('|');
        $this->assertStringContainsString('Nama Konsumen', $values);
        $this->assertStringNotContainsString('089999888777', $values);
        $this->assertStringNotContainsString('Catatan koordinator rahasia', $values);
        $workbook->disconnectWorksheets();
    }

    public function test_wp23_activity_uses_all_human_sources_and_marks_open_queue(): void
    {
        $lead = $this->lead($this->sales, 'Lead perhatian', '2026-08-01', $this->project, $this->branch);
        SalesLeadStatusHistory::create([
            'sales_lead_id' => $lead->id,
            'branch_id' => $lead->branch_id,
            'status' => SalesLeadStatus::Discussion,
            'source' => 'test',
            'changed_at' => '2026-08-02 09:00:00',
        ]);
        SalesLeadSiteVisit::create([
            'sales_lead_id' => $lead->id,
            'branch_id' => $lead->branch_id,
            'actor_id' => $this->sales->id,
            'status' => 'incomplete',
            'created_at' => '2026-08-03 09:00:00',
            'updated_at' => '2026-08-03 09:00:00',
        ]);
        DB::table('sales_lead_transfers')->insert([
            'sales_lead_id' => $lead->id,
            'from_sales_user_id' => $this->sales->id,
            'to_sales_user_id' => $this->sales->id,
            'actor_user_id' => $this->coordinator->id,
            'reason' => 'Tidak dapat dihubungi',
            'created_at' => '2026-08-04 09:00:00',
            'updated_at' => '2026-08-04 09:00:00',
        ]);
        DB::table('comments')->insert([
            'commentable_type' => SalesLead::class,
            'commentable_id' => $lead->id,
            'user_id' => $this->sales->id,
            'body' => 'Catatan manusia',
            'body_plain' => 'Catatan manusia',
            'source' => 'human',
            'lock_version' => 0,
            'created_at' => '2026-08-05 09:00:00',
            'updated_at' => '2026-08-05 09:00:00',
        ]);
        DB::table('comments')->insert([
            'commentable_type' => SalesLead::class,
            'commentable_id' => $lead->id,
            'user_id' => $this->sales->id,
            'body' => 'Komentar sistem',
            'body_plain' => 'Komentar sistem',
            'source' => 'system',
            'lock_version' => 0,
            'created_at' => '2026-08-10 09:00:00',
            'updated_at' => '2026-08-10 09:00:00',
        ]);
        DB::table('comments')->insert([
            'commentable_type' => SalesLead::class,
            'commentable_id' => $lead->id,
            'user_id' => $this->sales->id,
            'body' => 'Komentar impor',
            'body_plain' => 'Komentar impor',
            'source' => 'import',
            'lock_version' => 0,
            'created_at' => '2026-08-11 09:00:00',
            'updated_at' => '2026-08-11 09:00:00',
        ]);
        SalesLeadUnownedQueue::create([
            'sales_lead_id' => $lead->id,
            'queue_coordinator_user_id' => $this->coordinator->id,
            'status' => 'open',
        ]);

        $result = app(LeadAttentionService::class)->queryFor($this->coordinator)->get();

        $row = $result->firstWhere('id', $lead->id);
        $this->assertNotNull($row);
        $this->assertSame('2026-08-05 09:00:00', Carbon::parse($row->latest_activity_at)->format('Y-m-d H:i:s'));
        $this->assertTrue((bool) $row->needs_attention);
        $this->assertTrue((bool) $row->has_unowned_queue);
    }

    public function test_wp23_system_and_import_comments_do_not_override_human_activity(): void
    {
        $lead = $this->lead($this->sales, 'Lead komentar', '2026-08-01', $this->project, $this->branch);
        DB::table('comments')->insert([
            'commentable_type' => SalesLead::class,
            'commentable_id' => $lead->id,
            'user_id' => $this->sales->id,
            'body' => 'Manusia',
            'body_plain' => 'Manusia',
            'source' => 'human',
            'lock_version' => 0,
            'created_at' => '2026-08-02 09:00:00',
            'updated_at' => '2026-08-02 09:00:00',
        ]);
        DB::table('comments')->insert([
            'commentable_type' => SalesLead::class,
            'commentable_id' => $lead->id,
            'user_id' => $this->sales->id,
            'body' => 'Sistem',
            'body_plain' => 'Sistem',
            'source' => 'system',
            'lock_version' => 0,
            'created_at' => '2026-08-11 09:00:00',
            'updated_at' => '2026-08-11 09:00:00',
        ]);
        DB::table('comments')->insert([
            'commentable_type' => SalesLead::class,
            'commentable_id' => $lead->id,
            'user_id' => $this->sales->id,
            'body' => 'Komentar historis tanpa sumber',
            'body_plain' => 'Komentar historis tanpa sumber',
            'source' => null,
            'lock_version' => 0,
            'created_at' => '2026-08-12 09:00:00',
            'updated_at' => '2026-08-12 09:00:00',
        ]);

        $row = app(LeadAttentionService::class)->queryFor($this->coordinator)->find($lead->id);

        $this->assertSame('2026-08-02 09:00:00', Carbon::parse($row->latest_activity_at)->format('Y-m-d H:i:s'));
        $this->assertNotSame('2026-08-12 09:00:00', Carbon::parse($row->latest_activity_at)->format('Y-m-d H:i:s'));
    }

    public function test_wp23_legacy_closed_statuses_are_closed_when_v3_flag_is_off(): void
    {
        foreach (['utj', 'slik_check', 'slik_rejected', 'akad'] as $status) {
            $lead = $this->lead($this->sales, 'Legacy '.$status, '2026-08-01', $this->project, $this->branch);
            $lead->forceFill(['current_status' => $status])->save();
        }
        $lead = $this->lead($this->sales, 'Legacy UTJ Date', '2026-08-01', $this->project, $this->branch);
        $lead->forceFill(['utj_at' => '2026-08-02 09:00:00'])->save();

        $rows = app(LeadAttentionService::class)->queryFor($this->coordinator)->get();
        $this->assertSame([], $rows->filter(fn ($row): bool => (bool) $row->needs_attention)->pluck('customer_name')->all());
        $this->assertSame(0, app(LeadAttentionService::class)->attentionQuery($this->coordinator)->count());
    }

    public function test_wp23_per_lead_attention_matches_sql_for_v3_flag_states_and_statuses(): void
    {
        foreach ([false, true] as $enabled) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => 'lead_stages_v3'],
                ['value' => json_encode($enabled ? ['mode' => 'all', 'branch_ids' => []] : ['mode' => 'off', 'branch_ids' => []]), 'group' => 'feature_flags', 'updated_at' => now(), 'created_at' => now()],
            );
            app(AppSettingResolver::class)->forget('lead_stages_v3');
            foreach (['lead_baru', 'utj', 'gugur'] as $stage) {
                $lead = $this->lead($this->sales, "Stage {$enabled} {$stage}", '2026-08-01', $this->project, $this->branch);
                $lead->forceFill(['lead_stage' => $stage])->save();
                $this->assertSame(
                    app(LeadAttentionService::class)->attentionQuery($this->coordinator)->whereKey($lead->id)->exists(),
                    app(LeadAttentionService::class)->isAttentionNeeded($lead->fresh()),
                    "Mismatch for flag {$enabled}, stage {$stage}",
                );
            }
        }
    }

    public function test_wp23_old_human_comment_backfill_resets_activity_after_migration(): void
    {
        $lead = $this->lead($this->sales, 'Komentar lama', '2026-08-01', $this->project, $this->branch);
        $commentId = DB::table('comments')->insertGetId([
            'commentable_type' => SalesLead::class, 'commentable_id' => $lead->id, 'user_id' => $this->sales->id,
            'body' => 'Komentar lama', 'body_plain' => 'Komentar lama', 'source' => null, 'lock_version' => 0,
            'created_at' => '2026-08-10 09:00:00', 'updated_at' => '2026-08-10 09:00:00',
        ]);
        $migration = require database_path('migrations/2026_11_22_234524_add_source_to_comments_table.php');
        $migration->up();

        $this->assertSame('human', DB::table('comments')->where('id', $commentId)->value('source'));
        $this->assertSame('2026-08-10 09:00:00', Carbon::parse(app(LeadAttentionService::class)->latestActivityAt($lead->fresh()))->format('Y-m-d H:i:s'));
    }

    public function test_wp23_old_system_comment_backfill_does_not_reset_activity(): void
    {
        $lead = $this->lead($this->sales, 'Komentar sistem lama', '2026-08-01', $this->project, $this->branch);
        $commentId = DB::table('comments')->insertGetId([
            'commentable_type' => SalesLead::class, 'commentable_id' => $lead->id, 'user_id' => $this->sales->id,
            'body' => 'Komentar sistem', 'body_plain' => 'Komentar sistem', 'source' => null, 'lock_version' => 0,
            'created_at' => '2026-08-10 09:00:00', 'updated_at' => '2026-08-10 09:00:00',
        ]);
        $migration = require database_path('migrations/2026_11_22_234524_add_source_to_comments_table.php');
        $migration->up();
        DB::table('comments')->where('id', $commentId)->update(['user_id' => null, 'source' => null]);
        $migration->up();

        $this->assertSame('system', DB::table('comments')->where('id', $commentId)->value('source'));
    }

    public function test_wp23_closed_v3_leads_do_not_need_attention(): void
    {
        $lead = $this->lead($this->sales, 'Lead UTJ', '2026-08-01', $this->project, $this->branch);
        $lead->forceFill(['lead_stage' => 'utj'])->save();

        DB::table('app_settings')->insert([
            'key' => 'lead_stages_v3',
            'value' => json_encode(['mode' => 'all', 'branch_ids' => []], JSON_THROW_ON_ERROR),
            'group' => 'feature_flags',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(AppSettingResolver::class)->forget('lead_stages_v3');

        $row = app(LeadAttentionService::class)->queryFor($this->coordinator)->find($lead->id);

        $this->assertFalse((bool) $row->needs_attention);
    }

    public function test_wp23_lead_attention_list_uses_constant_query_count(): void
    {
        foreach (range(1, 4) as $index) {
            $this->lead($this->sales, "Lead {$index}", '2026-08-01', $this->project, $this->branch);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(LeadAttentionService::class)->queryFor($this->coordinator)->get();
        $firstCount = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'from "sales_leads"'))->count();

        foreach (range(5, 8) as $index) {
            $this->lead($this->sales, "Lead {$index}", '2026-08-01', $this->project, $this->branch);
        }
        DB::flushQueryLog();
        app(LeadAttentionService::class)->queryFor($this->coordinator)->get();
        $secondCount = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'from "sales_leads"'))->count();
        DB::disableQueryLog();

        $this->assertSame($firstCount, $secondCount);
    }

    public function test_br51_coordinator_actionable_and_failed_leads_use_projection_contract(): void
    {
        $stale = $this->lead($this->sales, 'Lead lama', '2026-08-01', $this->project, $this->branch);
        $stale->forceFill(['updated_at' => Carbon::parse('2026-08-08 09:00:00')])->save();
        $failedSync = $this->lead($this->sales, 'Lead gagal sync', '2026-08-11', $this->project, $this->branch);
        $failedSync->forceFill(['sync_status' => 'sync_failed'])->save();
        $projection = Mockery::mock(SalesMonitoringProjectionService::class);
        $projection->shouldReceive('projectCollection')->atLeast()->once()->andReturnUsing(
            fn ($actor, $type, $records) => $records->map(fn () => ['name' => 'Ringkasan'])
        );
        $this->app->instance(SalesMonitoringProjectionService::class, $projection);

        $data = app(CoordinatorSalesMonitoringService::class)->resolve($this->coordinator, [], false);

        $this->assertIsArray($data['actionableLeads']->first());
        $this->assertIsArray($data['failedSyncLeads']->first());
    }

    public function test_br51_coordinator_detail_projects_week_items_and_backlog(): void
    {
        $this->agenda($this->sales, 'Agenda sebelumnya', $this->branch, $this->project, '2026-08-10');
        $this->agenda($this->sales, 'Agenda hari ini', $this->branch, $this->project, '2026-08-11');
        $projection = Mockery::mock(SalesMonitoringProjectionService::class);
        $projection->shouldReceive('projectCollection')->atLeast()->once()->andReturnUsing(
            fn ($actor, $type, $records) => $records->map(fn ($record) => ['date' => $record->scheduled_date->toDateString()])
        );
        $this->app->instance(SalesMonitoringProjectionService::class, $projection);

        $detail = app(CoordinatorSalesMonitoringService::class)->salesDetail($this->coordinator, $this->sales->id);

        $this->assertIsArray($detail['backlog']->first());
        $this->assertIsArray($detail['week']->first()['items']->first());
    }

    public function test_br51_actionable_leads_use_status_activity_not_updated_at_for_stale_threshold(): void
    {
        $active = $this->lead($this->sales, 'Lead masih aktif', '2026-08-01', $this->project, $this->branch);
        $active->forceFill([
            'current_status_changed_at' => Carbon::parse('2026-08-10 09:00:00'),
            'updated_at' => Carbon::parse('2026-08-11 09:00:00'),
        ])->save();

        $data = app(CoordinatorSalesMonitoringService::class)->resolve($this->coordinator, [], false);

        $this->assertSame([], $data['actionableLeads']->pluck('customer_name')->all());
    }

    public function test_actionable_leads_use_adjustable_stale_threshold_and_report_sync_failures_separately(): void
    {
        $stale = $this->lead($this->sales, 'Lead lama', '2026-08-01', $this->project, $this->branch);
        $stale->forceFill(['updated_at' => Carbon::parse('2026-08-08 09:00:00')])->save();
        $failedSync = $this->lead($this->sales, 'Lead gagal sync', '2026-08-11', $this->project, $this->branch);
        $failedSync->forceFill(['sync_status' => 'sync_failed'])->save();

        $default = app(CoordinatorSalesMonitoringService::class)->resolve($this->coordinator, [], false);
        $custom = app(CoordinatorSalesMonitoringService::class)->resolve($this->coordinator, ['stale_days' => 5], false);

        $this->assertSame(['Lead lama'], $default['actionableLeads']->pluck('customer_name')->all());
        $this->assertSame(['Lead gagal sync'], $default['failedSyncLeads']->pluck('customer_name')->all());
        $this->assertSame(['Lead lama'], $custom['actionableLeads']->pluck('customer_name')->all());
    }

    public function test_wp23_actionable_lead_link_uses_supported_attention_focus(): void
    {
        $view = file_get_contents(resource_path('views/crm/sales-pocketbook/coordinator-leads.blade.php'));

        $this->assertStringContainsString("'focus' => 'attention'", $view);
        $this->assertStringNotContainsString("'focus' => 'stale'", $view);
    }

    public function test_wp23_coordinator_lead_screen_shows_attention_badge_and_focus_filter(): void
    {
        $stale = $this->lead($this->sales, 'Lead Perhatian', '2026-08-01', $this->project, $this->branch);
        $stale->forceFill(['current_status_changed_at' => Carbon::parse('2026-08-01 09:00:00')])->save();
        $fresh = $this->lead($this->sales, 'Lead Aktif', '2026-08-10', $this->project, $this->branch);
        $fresh->forceFill(['current_status_changed_at' => Carbon::parse('2026-08-11 09:00:00')])->save();

        $period = ['period' => 'custom', 'date_from' => '2026-08-01', 'date_to' => '2026-08-11'];

        $this->actingAs($this->coordinator)
            ->get(route('sales-pocketbook.index', ['tab' => 'lead', ...$period]))
            ->assertOk()
            ->assertSeeText('Perlu perhatian');

        $this->actingAs($this->coordinator)
            ->get(route('sales-pocketbook.index', ['tab' => 'lead', 'focus' => 'attention', ...$period]))
            ->assertOk()
            ->assertSeeText('Lead Perhatian')
            ->assertDontSeeText('Lead Aktif');
    }

    public function test_wp23_sales_lead_screen_shows_own_attention_badge(): void
    {
        $lead = $this->lead($this->sales, 'Lead Sales Perhatian', '2026-08-01', $this->project, $this->branch);
        $lead->forceFill(['current_status_changed_at' => Carbon::parse('2026-08-01 09:00:00')])->save();

        $this->actingAs($this->sales)
            ->get(route('sales-pocketbook.index', ['tab' => 'leads']))
            ->assertOk()
            ->assertSeeText('Lead Sales Perhatian')
            ->assertSeeText('Perlu perhatian');
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
