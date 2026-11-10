<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesAgendaDaySubmission;
use App\Models\User;
use App\Services\SalesPocketbookOnboardingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesAgendaWorkspaceRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_heading_matches_today_tomorrow_and_other_planned_dates(): void
    {
        [$sales] = $this->salesContext();
        $today = CarbonImmutable::today(config('app.timezone'));
        $tomorrow = $today->addDay();
        $laterDate = $today->addDays(2);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => $today->toDateString()]))
            ->assertOk()
            ->assertSee('RENCANA HARI INI');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => $tomorrow->toDateString()]))
            ->assertOk()
            ->assertSee('RENCANA BESOK')
            ->assertDontSee('RENCANA HARI INI');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => $laterDate->toDateString()]))
            ->assertOk()
            ->assertSee('Agenda '.$laterDate->locale('id')->translatedFormat('d F Y'))
            ->assertSee('AKHIR PEKAN')
            ->assertDontSee('RENCANA HARI INI');
    }

    public function test_plan_date_alias_accepts_any_selected_date_for_week_navigation(): void
    {
        [$sales] = $this->salesContext();
        $today = CarbonImmutable::today(config('app.timezone'));
        $futureDate = $today->addDays(5);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['plan_date' => $futureDate->toDateString()]))
            ->assertRedirect(route('sales-agendas.index', ['work_date' => $futureDate->toDateString()]));

        foreach ([$today, $today->subDay()] as $selectedDate) {
            $this->actingAs($sales)
                ->get(route('sales-agendas.index', ['plan_date' => $selectedDate->toDateString()]))
                ->assertRedirect(route('sales-agendas.index', ['work_date' => $selectedDate->toDateString()]));
        }
    }

    public function test_work_date_is_canonical_when_legacy_plan_date_is_also_present(): void
    {
        [$sales] = $this->salesContext();

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', [
                'work_date' => '2026-11-04',
                'plan_date' => '2026-11-05',
            ]))
            ->assertOk()
            ->assertViewHas('agendaDate', fn (CarbonImmutable $agendaDate): bool => $agendaDate->toDateString() === '2026-11-04')
            ->assertSee('data-agenda-date="2026-11-04"', false)
            ->assertSee('name="scheduled_date" value="2026-11-04"', false)
            ->assertDontSee('name="scheduled_date" value="2026-11-05"', false);
    }

    public function test_future_date_picker_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Rencana Agenda dapat memilih tanggal mendatang';
        $migration = require database_path('migrations/2026_11_02_112046_add_sales_agenda_future_date_picker_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);

        $this->actingAs($superadmin)
            ->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText($title);
    }

    public function test_weekend_direct_plan_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Rencana akhir pekan tersimpan langsung tanpa struk';
        $migration = require database_path('migrations/2026_11_02_130000_add_sales_agenda_weekend_direct_plan_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);

        $this->actingAs($superadmin)
            ->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText($title);
    }

    public function test_unified_shell_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Shell Agenda Buku Saku menyatukan status dan tampilan';
        $migration = require database_path('migrations/2026_11_02_140000_add_sales_agenda_unified_shell_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);

        $this->actingAs($superadmin)
            ->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText($title);
    }

    public function test_weekly_navigation_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Navigasi Agenda menggunakan minggu Senin sampai Minggu';
        $migration = require database_path('migrations/2026_11_02_114944_add_sales_agenda_weekly_navigation_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);

        $this->actingAs($superadmin)
            ->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText($title);
    }

    public function test_weekly_refinement_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Navigasi mingguan Agenda dirapikan untuk alur struk';
        $migration = require database_path('migrations/2026_11_02_123207_add_sales_agenda_weekly_refinement_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);

        $this->actingAs($superadmin)
            ->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText($title);
    }

    public function test_empty_agenda_renders_receipt_target_and_add_action(): void
    {
        [$sales, $project] = $this->salesContext();

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('sales-personal-page', false)
            ->assertSee('sales-reference-shell', false)
            ->assertSee('aria-labelledby="agenda-workspace-title"', false)
            ->assertSee('id="agenda-workspace-title"', false)
            ->assertSee('Buku Saku Saya')
            ->assertSee('Agenda')
            ->assertSee('Lead Saya')
            ->assertSee('0 / 480 menit')
            ->assertDontSee('data-agenda-workflow-status="empty"', false)
            ->assertDontSee('sales-agenda-workflow-status--empty', false)
            ->assertSee('Jam kerja')
            ->assertSee('07:30–17:00')
            ->assertSee('target hari ini')
            ->assertSee('TARGET HARIAN')
            ->assertSee('480 MENIT')
            ->assertDontSee('420 MENIT')
            ->assertSee('Belum ada rencana hari ini')
            ->assertSee('sales-reference-bottom-action', false)
            ->assertSee('+ Tambah rencana')
            ->assertSee('Belum ada agenda')
            ->assertSee($project->project_name);
    }

    public function test_existing_draft_shows_a_resume_action_and_resume_query_opens_receipt(): void
    {
        [$sales, $project] = $this->salesContext();
        $date = CarbonImmutable::today(config('app.timezone'));
        $this->agendaAt($sales, $project, $date->toDateString(), '09:00', '10:00', 'Draft tersimpan');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => $date->toDateString()]))
            ->assertOk()
            ->assertSeeText('Draft agenda tersimpan')
            ->assertSeeText('Lanjutkan Draft')
            ->assertDontSeeText('Belum ada rencana hari ini');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', [
                'work_date' => $date->toDateString(),
                'resume_receipt' => '1',
            ]))
            ->assertOk()
            ->assertSee('data-resume-receipt="true"', false)
            ->assertSeeText('Draft tersimpan');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', [
                'view' => 'all',
                'work_date' => $date->toDateString(),
                'resume_receipt' => '1',
            ]))
            ->assertOk()
            ->assertSee('data-resume-receipt="false"', false);
    }

    public function test_resumable_draft_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Draft Agenda Dapat Dilanjutkan dari Struk';
        $migration = require database_path('migrations/2026_11_05_152733_add_sales_agenda_resumable_draft_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);

        $this->actingAs($superadmin)
            ->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText($title);
    }

    public function test_adding_to_a_draft_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Draft Agenda Tetap Terbuka Saat Menambah Rencana';
        $migration = require database_path('migrations/2026_11_06_083359_add_sales_agenda_receipt_resume_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);

        $this->actingAs($superadmin)
            ->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText($title);
    }

    public function test_mobile_workspace_bottom_action_stays_fixed_through_embedded_tablet_widths(): void
    {
        [$sales] = $this->salesContext();
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertStringContainsString('@media (max-width: 1024px)', $css);
        $this->assertMatchesRegularExpression(
            '/body\.sales-personal-page \{[^}]*overflow-x: clip !important;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/body\.sales-personal-page #crm-main \{[^}]*overflow: visible !important;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/body\.sales-personal-page #agenda-input\.sales-reference-bottom-action \{[^}]*position: fixed !important;[^}]*inset-inline: 0 !important;[^}]*bottom: 0 !important;[^}]*justify-content: center !important;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/body\.sales-personal-page #agenda-input\.sales-reference-bottom-action \.sales-receipt-add-trigger \{[^}]*width: 100% !important;[^}]*max-width: 480px !important;[^}]*margin: 0 auto !important;/s',
            $css,
        );

        $response = $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-11-07']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<div id="agenda-input"[^>]*sales-reference-bottom-action[^>]*>.*?openAgendaSheet\(\$event\).*?aria-controls="sales-agenda-create-sheet"/s',
            $response,
        );
        $this->assertMatchesRegularExpression(
            '/body\.sales-personal-page #agenda-input\.sales-reference-bottom-action \{[^}]*position: fixed !important;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.sales-reference-bottom-action\.is-receipt-action \{[^}]*position: static !important;/s',
            $css,
        );
    }

    public function test_mobile_receipt_keeps_history_link_below_receipt_actions(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertMatchesRegularExpression(
            '/body\.sales-personal-page #agenda-list\.is-receipt-mode > \.sales-receipt-canvas \{[^}]*margin-bottom: 0 !important;/s',
            $css,
        );
    }

    public function test_populated_receipt_keeps_weekly_navigation_with_exact_date_links(): void
    {
        [$sales, $project] = $this->salesContext();
        $today = CarbonImmutable::today(config('app.timezone'));
        $yesterday = $today->subDay();

        $this->agendaAt($sales, $project, $yesterday->toDateString(), '09:00', '10:00', 'Agenda kemarin');
        $this->agendaAt($sales, $project, $today->toDateString(), '09:00', '10:00', 'Agenda hari ini');
        $this->agendaAt($sales, $project, $today->addDay()->toDateString(), '09:00', '10:00', 'Agenda rencana');

        $response = $this->actingAs($sales)
            ->get(route('sales-agendas.index', [
                'tab' => 'agenda',
                'work_date' => $yesterday->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Agenda kemarin')
            ->assertDontSee('Agenda hari ini')
            ->assertDontSee('Agenda rencana');

        $response
            ->assertSee('href="'.e(route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $yesterday->startOfWeek(CarbonImmutable::MONDAY)->toDateString()])).'"', false)
            ->assertSee('href="'.e(route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $yesterday->endOfWeek(CarbonImmutable::SUNDAY)->toDateString()])).'"', false)
            ->assertSee('data-agenda-week-navigation', false)
            ->assertSee('aria-current="date"', false);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', [
                'tab' => 'agenda',
                'work_date' => $today->addDay()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('data-week-day="'.$today->addDay()->toDateString().'"', false)
            ->assertSee('class="sales-week-navigation-day is-active"', false);
    }

    public function test_weekly_navigation_renders_monday_to_sunday_with_selected_date(): void
    {
        [$sales] = $this->salesContext();

        $response = $this->actingAs($sales)
            ->get(route('sales-agendas.index', [
                'tab' => 'agenda',
                'work_date' => '2026-11-08',
            ]))
            ->assertOk();

        $response
            ->assertSee('data-agenda-week-navigation', false)
            ->assertSee('Sen')
            ->assertSee('Sel')
            ->assertSee('Rab')
            ->assertSee('Kam')
            ->assertSee('Jum')
            ->assertSee('Sab')
            ->assertSee('Min')
            ->assertSee('data-week-day="2026-11-02"', false)
            ->assertSee('data-week-day="2026-11-08"', false)
            ->assertSee('aria-current="date"', false);
    }

    public function test_weekly_navigation_exposes_week_controls_without_date_picker(): void
    {
        [$sales] = $this->salesContext();

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', [
                'tab' => 'agenda',
                'work_date' => '2026-11-08',
            ]))
            ->assertOk()
            ->assertSee('work_date=2026-11-01', false)
            ->assertSee('work_date=2026-11-15', false)
            ->assertDontSee('sales-week-navigation-picker', false)
            ->assertDontSee('sales-agenda-week-date', false)
            ->assertDontSee('Pilih minggu', false);
    }

    public function test_weekly_navigation_marks_empty_and_unfinished_dates_without_false_alerts(): void
    {
        [$sales, $project] = $this->salesContext();
        $planned = $this->agendaAt($sales, $project, '2026-11-03', '09:00', '10:00', 'Agenda belum selesai');
        $done = $this->agendaAt($sales, $project, '2026-11-04', '09:00', '10:00', 'Agenda selesai');
        $cancelled = $this->agendaAt($sales, $project, '2026-11-05', '09:00', '10:00', 'Agenda dibatalkan');
        $rescheduled = $this->agendaAt($sales, $project, '2026-11-06', '09:00', '10:00', 'Agenda ditunda');
        $done->update(['status' => 'done']);
        $cancelled->update(['status' => 'cancelled']);
        $rescheduled->update(['status' => 'rescheduled']);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => '2026-11-02']))
            ->assertOk()
            ->assertSee('data-week-day-state="empty"', false)
            ->assertSee('data-week-day-state="attention"', false)
            ->assertSee('aria-label="Selasa, 03 November 2026, 1 agenda belum selesai"', false)
            ->assertSee('data-week-day="2026-11-04" data-week-day-state="ready"', false)
            ->assertSee('data-week-day="2026-11-05" data-week-day-state="empty"', false)
            ->assertSee('data-week-day="2026-11-06" data-week-day-state="empty"', false);
    }

    public function test_today_navigation_marks_only_the_selected_week_day_as_active(): void
    {
        [$sales] = $this->salesContext();
        $today = CarbonImmutable::today(config('app.timezone'));

        $response = $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $today->toDateString()]))
            ->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('class="sales-week-navigation-day is-active" data-week-day="'.$today->toDateString().'"', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="date"'));
    }

    public function test_mobile_receipt_preview_uses_save_draft_label_without_changing_submit_contract(): void
    {
        [$sales, $project] = $this->salesContext();

        $response = $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk();

        $response
            ->assertSee('class="sales-receipt-preview-header"', false)
            ->assertSee('Simpan Draft')
            ->assertDontSee('Simpan draft struk')
            ->assertSee('type="button" x-ref="receiptClose"', false)
            ->assertSee('name="custom_duration_minutes"', false)
            ->assertDontSee('name="duration_minutes" required', false)
            ->assertDontSee('class="sales-receipt-preview-header"><span>Struk agenda</span><button', false);

        $this->agendaAt($sales, $project, '2026-10-30', '07:30', '17:00', 'Agenda utama');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('data-receipt-submit', false);
    }

    public function test_all_agenda_view_is_owner_scoped_and_preserves_history_action(): void
    {
        [$sales, $project] = $this->salesContext();
        $otherSales = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'sales')->value('id'),
            'branch_id' => $project->branch_id,
            'password_changed_at' => now(),
        ]);
        $otherSales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);
        app(SalesPocketbookOnboardingService::class)->skip($otherSales);

        $inaccessibleBranch = Branch::create(['name' => 'Cabang Tertutup', 'code' => 'CT', 'is_active' => true]);
        $inaccessibleProject = LeadMaster::create(['branch_id' => $inaccessibleBranch->id, 'project_name' => 'Proyek Tertutup', 'is_active' => true]);

        $this->agendaAt($sales, $project, '2026-10-29', '09:00', '10:00', 'Agenda lama milik saya');
        $this->agendaAt($sales, $project, '2026-11-03', '09:00', '10:00', 'Agenda depan milik saya');
        $this->agendaAt($otherSales, $project, '2026-10-29', '09:00', '10:00', 'Agenda milik Sales lain');
        $this->agendaAtForBranch($sales, $inaccessibleBranch, $inaccessibleProject, '2026-10-29', '09:00', '10:00', 'Agenda proyek tertutup');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', [
                'tab' => 'agenda',
                'view' => 'all',
                'work_date' => '2026-10-30',
            ]))
            ->assertOk()
            ->assertSee('Agenda lama milik saya')
            ->assertSee('Agenda depan milik saya')
            ->assertDontSee('Agenda milik Sales lain')
            ->assertDontSee('Agenda proyek tertutup');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('view=all', false)
            ->assertSee('work_date=2026-10-30', false);
    }

    public function test_all_agenda_view_uses_compact_rows_for_mobile_scanning(): void
    {
        [$sales, $project] = $this->salesContext();
        $agenda = $this->agendaAt($sales, $project, '2026-10-29', '09:00', '10:00', 'Agenda ringkas lintas tanggal');
        $agenda->update(['sales_activity_category' => 'Canvassing']);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', [
                'tab' => 'agenda',
                'view' => 'all',
                'work_date' => '2026-10-30',
            ]))
            ->assertOk()
            ->assertSee('sales-agenda-history-compact-row', false)
            ->assertSee('sales-agenda-history-compact-time', false)
            ->assertSee('sales-agenda-history-compact-category', false)
            ->assertSee('sales-agenda-history-compact-status', false)
            ->assertSee('Agenda ringkas lintas tanggal')
            ->assertSee('return_view=all', false)
            ->assertDontSee('sales-agenda-history-facts', false);
    }

    public function test_receipt_ignores_same_day_agenda_outside_sales_scope(): void
    {
        [$sales] = $this->salesContext();
        $inaccessibleBranch = Branch::create(['name' => 'Cabang Tertutup', 'code' => 'CT', 'is_active' => true]);
        $inaccessibleProject = LeadMaster::create(['branch_id' => $inaccessibleBranch->id, 'project_name' => 'Proyek Tertutup', 'is_active' => true]);
        $this->agendaAtForBranch($sales, $inaccessibleBranch, $inaccessibleProject, '2026-10-30', '09:00', '10:00', 'Agenda proyek tertutup');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('0 / 480 menit')
            ->assertDontSee('Agenda proyek tertutup');
    }

    public function test_agenda_detail_back_link_preserves_history_view_and_selected_date(): void
    {
        [$sales, $project] = $this->salesContext();
        $agenda = $this->agendaAt($sales, $project, '2026-10-29', '09:00', '10:00', 'Agenda riwayat detail');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', [
                'tab' => 'agenda',
                'view' => 'all',
                'work_date' => '2026-10-30',
            ]))
            ->assertOk()
            ->assertSee('return_view=all', false)
            ->assertSee('return_work_date=2026-10-30', false);

        $this->actingAs($sales)
            ->get(route('sales-agendas.show', [
                'agenda' => $agenda,
                'return_view' => 'all',
                'return_work_date' => '2026-10-30',
            ]))
            ->assertOk()
            ->assertSee('work_date=2026-10-30&amp;view=all', false);
    }

    public function test_all_agenda_action_counts_only_unfinished_statuses(): void
    {
        [$sales, $project] = $this->salesContext();
        $planned = $this->agendaAt($sales, $project, '2026-10-29', '09:00', '10:00', 'Agenda planned');
        $confirmed = $this->agendaAt($sales, $project, '2026-10-29', '10:00', '11:00', 'Agenda confirmed');
        $done = $this->agendaAt($sales, $project, '2026-10-29', '11:00', '12:00', 'Agenda done');
        $cancelled = $this->agendaAt($sales, $project, '2026-10-29', '13:00', '14:00', 'Agenda cancelled');
        $rescheduled = $this->agendaAt($sales, $project, '2026-10-29', '14:00', '15:00', 'Agenda rescheduled');
        $confirmed->update(['status' => 'confirmed']);
        $done->update(['status' => 'done']);
        $cancelled->update(['status' => 'cancelled']);
        $rescheduled->update(['status' => 'rescheduled']);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('Lihat semua agenda')
            ->assertSee('2 belum selesai');
    }

    public function test_all_agenda_history_paginates_while_preserving_view_context(): void
    {
        [$sales, $project] = $this->salesContext();

        for ($index = 1; $index <= 21; $index++) {
            $this->agendaAt(
                $sales,
                $project,
                CarbonImmutable::parse('2026-10-01')->addDays($index)->toDateString(),
                '09:00',
                '10:00',
                'Agenda riwayat '.$index,
            );
        }

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', [
                'tab' => 'agenda',
                'view' => 'all',
                'work_date' => '2026-10-30',
                'agenda_page' => 2,
            ]))
            ->assertOk()
            ->assertSee('Agenda riwayat 1')
            ->assertSee('agenda_page=1', false)
            ->assertSee('view=all', false)
            ->assertSee('work_date=2026-10-30', false);
    }

    public function test_compact_agenda_history_changelog_migration_is_idempotent(): void
    {
        $title = 'Daftar Semua Agenda Lebih Ringkas';
        DB::table('changelogs')->whereNull('version')->where('title', $title)->delete();

        $migration = require database_path('migrations/2026_11_03_140000_add_sales_agenda_history_compact_changelog.php');
        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->assertDatabaseHas('changelogs', [
            'version' => null,
            'title' => $title,
            'category' => 'changed',
        ]);

        $migration->down();

        $this->assertSame(0, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
    }

    public function test_agenda_history_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Perbaikan draft, durasi custom, dan riwayat Agenda Sales';
        $migration = require database_path('migrations/2026_11_02_120100_add_sales_agenda_history_ux_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);

        $this->actingAs($superadmin)
            ->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText($title);
    }

    public function test_workspace_uses_separate_focus_state_for_preview_and_create_sheet(): void
    {
        [$sales] = $this->salesContext();
        $source = file_get_contents(resource_path('js/sales-agenda-workspace.js'));

        $this->assertIsString($source);
        $this->assertStringContainsString('receiptTrigger: null', $source);
        $this->assertStringContainsString('agendaTrigger: null', $source);
        $this->assertStringContainsString("receiptLockOwner: 'sales-agenda-receipt-preview'", $source);
        $this->assertStringContainsString("agendaLockOwner: 'sales-agenda-create-sheet'", $source);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index'))
            ->assertOk();
    }

    public function test_date_navigation_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Navigasi tanggal Buku Saku Sales menampilkan agenda yang dipilih';
        $migration = require database_path('migrations/2026_11_02_083327_add_sales_agenda_date_navigation_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);

        $this->actingAs($superadmin)
            ->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText($title);
    }

    public function test_agenda_date_context_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Struk Agenda mempertahankan tanggal rencana';
        $migration = require database_path('migrations/2026_11_02_105204_add_sales_agenda_date_context_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $superadmin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);

        $this->actingAs($superadmin)
            ->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText($title);
    }

    public function test_receipt_formats_open_slot_start_time_as_clock_time(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agenda($sales, $project, 420, false, '2026-10-30');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('16:00–17:00 · kosong 60 menit')
            ->assertDontSee('960–17:00 · kosong 60 menit');
    }

    public function test_open_slot_guidance_uses_effective_minutes_after_the_break(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agendaAt($sales, $project, '2026-10-30', '09:00', '10:00', 'Agenda pagi');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('10:00–17:00 · kosong 330 menit');
    }

    public function test_break_start_preview_uses_effective_remaining_minutes(): void
    {
        [$sales] = $this->salesContext();

        $this->withSession(['_old_input' => [
            'start_time' => '12:00',
            'duration_minutes' => '60',
        ]])->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('Selesai 13:00 · sisa 480 menit');
    }

    public function test_weekend_agenda_renders_free_plan_without_target_or_break_row(): void
    {
        [$sales] = $this->salesContext();

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-11-01']))
            ->assertOk()
            ->assertSee('Akhir pekan')
            ->assertSee('AKHIR PEKAN')
            ->assertDontSee('data-agenda-workflow-status="weekend"', false)
            ->assertDontSee('sales-agenda-workflow-status--weekend', false)
            ->assertSee('Tanpa target')
            ->assertSee('openAgendaSheet($event)', false)
            ->assertDontSee('openAgendaPreview($event)', false)
            ->assertDontSee('data-agenda-receipt-preview', false)
            ->assertDontSee('sales-receipt-canvas', false)
            ->assertDontSee('sales-receipt-submit-form', false)
            ->assertSee('Tambah Rencana')
            ->assertSee('Tambahkan Rencana')
            ->assertDontSee('TARGET HARIAN')
            ->assertDontSee('420 menit')
            ->assertDontSee('Istirahat');
    }

    public function test_weekend_navigation_and_receipt_surfaces_have_distinct_visual_contract(): void
    {
        [$sales] = $this->salesContext();
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertMatchesRegularExpression(
            '/\.sales-week-navigation-day\.is-weekend::before \{[^}]*repeating-linear-gradient/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.sales-receipt-paper\.is-weekend,\s*\.sales-submitted-plan\.is-weekend \{[^}]*background:/s',
            $css,
        );
        $this->assertStringContainsString('body.sales-personal-page .sales-submitted-plan.is-weekend', $css);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-11-01']))
            ->assertOk()
            ->assertSee('data-week-day-weekend="true"', false);

        $migration = require database_path('migrations/2026_11_03_120000_add_sales_agenda_weekend_visual_changelog.php');
        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', 'Agenda Akhir Pekan Lebih Mudah Dibedakan')->count());
    }

    public function test_mobile_modal_animation_changelog_migration_is_idempotent(): void
    {
        $title = 'Animasi Modal Mobile dari Bawah';
        DB::table('changelogs')->whereNull('version')->where('title', $title)->delete();

        $migration = require database_path('migrations/2026_11_03_130000_add_mobile_modal_animation_changelog.php');
        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->assertDatabaseHas('changelogs', [
            'version' => null,
            'title' => $title,
            'category' => 'changed',
        ]);

        $migration->down();

        $this->assertSame(0, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
    }

    public function test_agenda_modal_avoids_native_picker_autofocus_and_has_keyboard_navigation(): void
    {
        [$sales] = $this->salesContext();

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-11-02']))
            ->assertOk()
            ->assertSee('@keydown="handleAgendaFormKeydown($event)"', false)
            ->assertSee('x-ref="sheetClose"', false)
            ->assertDontSee('name="sales_activity_category" required data-autofocus', false)
            ->assertSee('sales-receipt-preview-transition', false);

        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertStringContainsString('max-height: calc(100dvh - env(safe-area-inset-top, 0px)) !important;', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/body\.sales-personal-page \.sales-bottom-sheet \{[^}]*transform:\s*none/s',
            $css,
        );
    }

    public function test_sales_agenda_modal_interaction_changelog_migration_is_idempotent(): void
    {
        $title = 'Perbaikan Modal dan Input Agenda Mobile';
        DB::table('changelogs')->whereNull('version')->where('title', $title)->delete();

        $migration = require database_path('migrations/2026_11_05_144536_add_sales_agenda_modal_interaction_changelog.php');
        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->assertDatabaseHas('changelogs', [
            'version' => null,
            'title' => $title,
            'category' => 'fixed',
        ]);

        $migration->down();

        $this->assertSame(0, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
    }

    public function test_unsubmitted_weekend_agenda_renders_saved_plan_without_receipt(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agendaAt($sales, $project, '2026-11-01', '09:00', '09:30', 'Agenda akhir pekan tersimpan');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-11-01']))
            ->assertOk()
            ->assertSee('sales-weekend-plan', false)
            ->assertSee('Agenda akhir pekan tersimpan')
            ->assertSee('+ Tambah rencana akhir pekan')
            ->assertSee('openAgendaSheet($event)', false)
            ->assertDontSee('sales-receipt-canvas', false)
            ->assertDontSee('sales-receipt-submit-form', false)
            ->assertDontSee('Sobek &amp; kirim ke Koordinator', false);
    }

    public function test_break_agenda_renders_distinct_status_label(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agendaAt($sales, $project, '2026-10-30', '11:30', '13:30', 'Agenda melintasi istirahat');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('Agenda jam istirahat')
            ->assertSee('is-break', false);
    }

    public function test_submitted_outside_break_agenda_renders_both_status_labels(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agendaAt($sales, $project, '2026-10-30', '07:30', '17:00', 'Agenda utama');
        $this->agendaAt($sales, $project, '2026-10-30', '12:00', '13:00', 'Agenda tambahan istirahat', true);
        SalesAgendaDaySubmission::create([
            'owner_user_id' => $sales->id,
            'scheduled_date' => '2026-10-30',
            'target_minutes' => 480,
            'normal_minutes_at_submission' => 480,
            'submitted_at' => now(),
        ]);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('Agenda tambahan · di luar target · Agenda jam istirahat')
            ->assertSee('sales-submitted-row is-break', false);
    }

    public function test_flexible_agenda_row_does_not_render_placeholder_clock_or_zero_minutes(): void
    {
        [$sales, $project] = $this->salesContext();
        $agenda = ContentItem::create([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => 'Agenda fleksibel',
            'sales_activity_category' => 'Follow-up',
            'scheduled_date' => '2026-10-30',
            'start_time' => null,
            'end_time' => null,
            'duration_minutes' => null,
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
        $agenda->assignees()->sync([$sales->id]);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('Fleksibel')
            ->assertDontSee('--:--')
            ->assertDontSee('0m</strong>', false);
    }

    public function test_mobile_agenda_form_renders_slot_controls_and_sticky_action_contract(): void
    {
        [$sales] = $this->salesContext();

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('data-agenda-slot-form', false)
            ->assertSee('data-duration-option="30"', false)
            ->assertSee('data-duration-option="60"', false)
            ->assertSee('data-duration-option="90"', false)
            ->assertSee('data-duration-option="120"', false)
            ->assertSee('Custom', false)
            ->assertSee('Selesai', false)
            ->assertSee('sisa', false)
            ->assertSee('sales-receipt-form-actions', false)
            ->assertSee('Tambahkan Rencana');
    }

    public function test_mobile_receipt_preview_keeps_submit_action_above_add_action_bar(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertStringContainsString(
            'body.sales-personal-page #agenda-input.sales-reference-bottom-action',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.sales-reference-bottom-action\.is-receipt-action\s*\{[^}]*position:\s*static\s*!important;/s',
            $css,
        );
    }

    public function test_mobile_empty_agenda_requires_receipt_preview_before_add_form(): void
    {
        [$sales] = $this->salesContext();

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('openAgendaPreview', false)
            ->assertSee('data-agenda-receipt-preview', false)
            ->assertSee('data-receipt-preview-add', false)
            ->assertSee('openAgendaSheet', false);
    }

    public function test_unsubmitted_agenda_is_saved_in_receipt_preview_with_a_resume_action(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agendaAt($sales, $project, '2026-10-30', '07:30', '17:00', 'Agenda draft');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('sales-receipt-workspace ', false)
            ->assertDontSee('is-receipt-mode', false)
            ->assertSeeText('Draft agenda tersimpan')
            ->assertSeeText('Lanjutkan Draft')
            ->assertSee('sales-receipt-canvas', false)
            ->assertSee('sales-receipt-submit-form', false)
            ->assertSee('Sobek &amp; kirim ke Koordinator', false)
            ->assertDontSee('<div class="sales-submitted-plan"', false)
            ->assertDontSee('Terkirim ke Koordinator');
    }

    public function test_weekday_draft_keeps_buku_saku_shell_and_exposes_workflow_status(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agendaAt($sales, $project, '2026-10-30', '07:30', '17:00', 'Agenda draft shell');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('sales-reference-header', false)
            ->assertSee('sales-pocketbook-tabs', false)
            ->assertDontSee('data-agenda-workflow-status="draft"', false)
            ->assertSee('sales-draft-stamp', false)
            ->assertSee('>DRAFT</b>', false)
            ->assertSee('sales-receipt-canvas', false)
            ->assertSeeText('Lanjutkan Draft')
            ->assertSee('data-agenda-receipt-preview', false)
            ->assertSee('sales-receipt-submit-form', false);
    }

    public function test_submitted_receipt_renders_status_and_extra_work_state(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agendaAt($sales, $project, '2026-10-30', '07:30', '17:00', 'Agenda utama');
        $extra = $this->agenda($sales, $project, 30, true, '2026-10-30');
        SalesAgendaDaySubmission::create([
            'owner_user_id' => $sales->id,
            'scheduled_date' => '2026-10-30',
            'target_minutes' => 480,
            'normal_minutes_at_submission' => 480,
            'submitted_at' => now(),
        ]);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertDontSee('data-agenda-workflow-status="submitted"', false)
            ->assertDontSee('sales-agenda-workflow-status--submitted', false)
            ->assertSee('aria-labelledby="agenda-workspace-title"', false)
            ->assertSee('id="agenda-workspace-title"', false)
            ->assertSee('sales-submitted-plan', false)
            ->assertSee('Terkirim ke Koordinator')
            ->assertSee('Waktu, judul, kategori, dan lokasi agenda terjadwal tidak dapat diubah.')
            ->assertSee('Agenda tambahan · di luar target')
            ->assertSee('data-agenda-submitted="true"', false)
            ->assertSee($extra->title)
            ->assertDontSee('Sobek &amp; kirim ke Koordinator', false);
    }

    public function test_submitted_weekend_receipt_keeps_weekend_badge_and_no_target_footer(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agendaAt($sales, $project, '2026-11-01', '09:00', '09:30', 'Agenda utama');
        SalesAgendaDaySubmission::create([
            'owner_user_id' => $sales->id,
            'scheduled_date' => '2026-11-01',
            'target_minutes' => 0,
            'normal_minutes_at_submission' => 30,
            'submitted_at' => now(),
        ]);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-11-01']))
            ->assertOk()
            ->assertSee('Tersimpan langsung')
            ->assertDontSee('Terkirim ke Koordinator')
            ->assertSee('AKHIR PEKAN')
            ->assertSee('TOTAL 30 MENIT')
            ->assertSee('TANPA TARGET')
            ->assertSee('+ Tambah rencana akhir pekan')
            ->assertSee('sales-agenda-create-sheet', false)
            ->assertSee('openAgendaSheet($event)', false)
            ->assertDontSee('data-agenda-receipt-preview', false)
            ->assertDontSee('sales-receipt-submit-form', false)
            ->assertSee('Simpan agenda tambahan');
    }

    public function test_lead_saya_renders_counts_cards_and_add_action(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['tab' => 'leads']))
            ->assertOk()
            ->assertSee('Belum selesai')
            ->assertSee('Selesai')
            ->assertSee('+ Tambah lead')
            ->assertSee('Tambah lead')
            ->assertDontSee('Struk Agenda');
    }

    private function salesContext(): array
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis Solo', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales']);
        $sales = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);
        app(SalesPocketbookOnboardingService::class)->skip($sales);

        return [$sales, $project];
    }

    private function agenda(User $sales, LeadMaster $project, int $duration, bool $outsideSchedule = false, string $scheduledDate = '2026-10-31'): ContentItem
    {
        $agenda = ContentItem::create([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => $outsideSchedule ? 'Agenda tambahan' : 'Agenda utama',
            'sales_activity_category' => 'Follow-up',
            'scheduled_date' => $scheduledDate,
            'start_time' => '09:00',
            'end_time' => $duration === 420 ? '16:00' : '16:30',
            'duration_minutes' => $duration,
            'status' => 'planned',
            'is_outside_schedule' => $outsideSchedule,
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
        $agenda->assignees()->sync([$sales->id]);

        return $agenda;
    }

    private function agendaAt(User $sales, LeadMaster $project, string $date, string $start, string $end, string $title, bool $outsideSchedule = false): ContentItem
    {
        $agenda = ContentItem::create([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => $title,
            'sales_activity_category' => 'Follow-up',
            'scheduled_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'duration_minutes' => ((int) substr($end, 0, 2) * 60 + (int) substr($end, 3, 2)) - ((int) substr($start, 0, 2) * 60 + (int) substr($start, 3, 2)),
            'status' => 'planned',
            'is_outside_schedule' => $outsideSchedule,
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
        $agenda->assignees()->sync([$sales->id]);

        return $agenda;
    }

    private function agendaAtForBranch(User $sales, Branch $branch, LeadMaster $project, string $date, string $start, string $end, string $title): ContentItem
    {
        return ContentItem::create([
            'branch_id' => $branch->id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => $title,
            'sales_activity_category' => 'Follow-up',
            'scheduled_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'duration_minutes' => 60,
            'status' => 'planned',
            'is_outside_schedule' => false,
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
    }
}
