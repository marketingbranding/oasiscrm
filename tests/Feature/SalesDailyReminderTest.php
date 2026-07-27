<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\LeadSource;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\OptimisticLockService;
use App\Services\SalesDailyReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SalesDailyReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_first_eligible_visit_shows_dynamic_daily_reminder(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 08:00:00', config('app.timezone')));
        [, , $sales] = $this->salesContext();

        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('Pengingat Hari Ini')
            ->assertSee('Belum ada lead yang dicatat hari ini.')
            ->assertSee('Belum ada planning atau agenda hari ini.')
            ->assertSee('Sembunyikan pengingat untuk hari ini')
            ->assertSee('Input Lead')
            ->assertSee('Isi Agenda')
            ->assertSee('Nanti Saja')
            ->assertViewHas('dailyReminder', fn (array $state) => $state['shouldShow']
                && $state['todayLeadCount'] === 0
                && $state['todayAgendaCount'] === 0
                && $state['missingAgendaResultCount'] === 0
                && $state['hasAssignedProject']);
    }

    public function test_no_lead_no_agenda_and_missing_result_each_trigger_reminder(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 09:00:00', config('app.timezone')));
        [, $project, $sales] = $this->salesContext();
        $this->agenda($sales, $project, ['activity_result' => 'Sudah lengkap']);

        $state = app(SalesDailyReminderService::class)->state($sales);
        $this->assertTrue($state['shouldShow']);
        $this->assertSame(0, $state['todayLeadCount']);
        $this->assertSame(1, $state['todayAgendaCount']);

        $this->lead($sales, $project);
        ContentItem::query()->delete();
        $state = app(SalesDailyReminderService::class)->state($sales);
        $this->assertTrue($state['shouldShow']);
        $this->assertSame(1, $state['todayLeadCount']);
        $this->assertSame(0, $state['todayAgendaCount']);

        $this->agenda($sales, $project, ['status' => 'done', 'activity_result' => '   ', 'completed_at' => now()]);
        $state = app(SalesDailyReminderService::class)->state($sales);
        $this->assertTrue($state['shouldShow']);
        $this->assertSame(1, $state['missingAgendaResultCount']);
    }

    public function test_complete_daily_state_suppresses_reminder(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 09:00:00', config('app.timezone')));
        [, $project, $sales] = $this->salesContext();
        $this->lead($sales, $project);
        $this->agenda($sales, $project);

        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertViewHas('dailyReminder', fn (array $state) => ! $state['shouldShow']
                && $state['todayLeadCount'] === 1
                && $state['todayAgendaCount'] === 1
                && $state['missingAgendaResultCount'] === 0);
    }

    public function test_reminder_actions_suppress_only_destination_request_and_missing_results_are_actionable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 09:00:00', config('app.timezone')));
        [, $project, $sales] = $this->salesContext();
        $oldAgenda = $this->agenda($sales, $project, [
            'title' => 'Agenda Lama Tanpa Hasil',
            'scheduled_date' => '2026-06-01',
            'start_date' => '2026-06-01',
            'deadline_date' => '2026-06-01',
            'status' => 'done',
            'activity_result' => null,
            'completed_at' => '2026-06-01 10:00:00',
        ]);

        $leadAction = $this->actingAs($sales)->get(route('sales-pocketbook.index', [
            'input' => 1,
            'reminder_action' => 'lead',
        ]))->assertRedirect();
        $this->get($leadAction->headers->get('Location'))->assertOk()
            ->assertViewHas('dailyReminder', fn (array $state) => ! $state['shouldShow']);

        $resultAction = $this->actingAs($sales)->get(route('sales-pocketbook.index', [
            'tab' => 'agenda',
            'report_agenda_missing_result' => 1,
            'reminder_action' => 'result',
        ]))->assertRedirect();
        $this->get($resultAction->headers->get('Location'))->assertOk()
            ->assertSee($oldAgenda->title)
            ->assertSee('Tandai Selesai')
            ->assertViewHas('dailyReminder', fn (array $state) => ! $state['shouldShow']);

        $this->actingAs($sales)->patch(route('sales-agendas.update', $oldAgenda), [
            'activity_result' => 'Hasil lama sudah dilengkapi.',
            'expected_updated_at' => app(OptimisticLockService::class)->token($oldAgenda),
        ])->assertRedirect();
        $this->assertSame('2026-06-01 10:00:00', $oldAgenda->fresh()->completed_at->format('Y-m-d H:i:s'));

        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertViewHas('dailyReminder', fn (array $state) => $state['shouldShow']);
    }

    public function test_conflict_redirect_takes_precedence_over_daily_reminder(): void
    {
        [, , $sales] = $this->salesContext();

        $this->actingAs($sales)->withSession(['conflict_data' => [
            'code' => 'record_modified',
            'message' => 'Data berubah',
            'reload_url' => route('sales-pocketbook.index'),
        ]])->get(route('sales-pocketbook.index'))->assertOk()
            ->assertViewHas('dailyReminder', fn (array $state) => ! $state['shouldShow']);
    }

    public function test_dismiss_failure_warning_survives_navigation_and_cleans_url_client_side(): void
    {
        [, , $sales] = $this->salesContext();

        $this->actingAs($sales)->get(route('content-calendar.index', ['reminder_dismiss_failed' => 1]))->assertOk()
            ->assertSee('Pengingat belum dapat disembunyikan untuk hari ini. Pengingat mungkin muncul kembali.')
            ->assertSee('history.replaceState', false);
    }

    public function test_dismissal_is_idempotent_per_user_and_date_and_reappears_next_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 10:00:00', config('app.timezone')));
        [, , $sales] = $this->salesContext();
        $payload = ['reminder_key' => SalesDailyReminderService::KEY];

        $this->actingAs($sales)->postJson(route('sales-reminders.dismiss'), $payload)
            ->assertOk()->assertJsonPath('dismissed_for_date', '2026-07-27');
        $this->actingAs($sales)->postJson(route('sales-reminders.dismiss'), $payload)->assertOk();
        $this->assertDatabaseCount('user_daily_reminder_dismissals', 1);
        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertViewHas('dailyReminder', fn (array $state) => ! $state['shouldShow']);

        Carbon::setTestNow(Carbon::parse('2026-07-28 00:01:00', config('app.timezone')));
        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertViewHas('dailyReminder', fn (array $state) => $state['shouldShow'] && $state['today'] === '2026-07-28');
    }

    public function test_dismissal_is_isolated_between_sales_users(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 10:00:00', config('app.timezone')));
        [$branch, $project, $sales] = $this->salesContext();
        $other = $this->sales($branch, $project, 'Other Sales');

        $this->actingAs($sales)->postJson(route('sales-reminders.dismiss'), [
            'reminder_key' => SalesDailyReminderService::KEY,
        ])->assertOk();

        $this->assertFalse(app(SalesDailyReminderService::class)->state($sales)['shouldShow']);
        $this->assertTrue(app(SalesDailyReminderService::class)->state($other)['shouldShow']);
    }

    public function test_dismiss_endpoint_rejects_non_sales_invalid_keys_user_and_client_date(): void
    {
        [$branch, , $sales] = $this->salesContext();
        $manager = $this->user('manager', $branch);

        $this->actingAs($manager)->postJson(route('sales-reminders.dismiss'), [
            'reminder_key' => SalesDailyReminderService::KEY,
        ])->assertForbidden();
        $invalidKey = $this->actingAs($sales)->postJson(route('sales-reminders.dismiss'), [
            'reminder_key' => 'arbitrary_key',
        ]);
        $this->assertSame(422, $invalidKey->status(), $invalidKey->getContent());
        $spoofed = $this->actingAs($sales)->postJson(route('sales-reminders.dismiss'), [
            'reminder_key' => SalesDailyReminderService::KEY,
            'user_id' => $manager->id,
            'dismissed_for_date' => '2030-01-01',
        ]);
        $this->assertSame(422, $spoofed->status(), $spoofed->getContent());
        $this->assertDatabaseCount('user_daily_reminder_dismissals', 0);
    }

    public function test_dismiss_date_uses_application_timezone_and_route_keeps_web_csrf_middleware(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 17:30:00', 'UTC'));
        [, , $sales] = $this->salesContext();

        $this->actingAs($sales)->postJson(route('sales-reminders.dismiss'), [
            'reminder_key' => SalesDailyReminderService::KEY,
        ])->assertOk()->assertJsonPath('dismissed_for_date', '2026-07-28');

        $middleware = Route::getRoutes()->getByName('sales-reminders.dismiss')->gatherMiddleware();
        $this->assertContains('web', $middleware);
    }

    public function test_no_assigned_project_shows_guidance_without_lead_action_and_keeps_planner_action(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $sales = $this->user('sales', $branch);

        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('Anda belum ditugaskan ke proyek. Hubungi admin pusat.')
            ->assertDontSee('>Input Lead<', false)
            ->assertSee('>Isi Agenda<', false)
            ->assertViewHas('dailyReminder', fn (array $state) => $state['shouldShow']
                && ! $state['hasAssignedProject']
                && $state['agendaInputUrl'] === route('content-calendar.create', ['type' => 'agenda']));
        $this->actingAs($sales)->get(route('content-calendar.index'))->assertOk();
    }

    public function test_non_sales_never_receives_sales_daily_reminder(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $manager = $this->user('manager', $branch);

        $this->actingAs($manager)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertDontSee('salesDailyReminder(', false)
            ->assertViewHas('dailyReminder', fn (array $state) => ! $state['shouldShow']);
    }

    public function test_modal_uses_accessible_alpine_flow_and_non_blocking_dismiss_failure(): void
    {
        $view = file_get_contents(resource_path('views/crm/sales-pocketbook/_daily-reminder.blade.php'));
        $script = file_get_contents(resource_path('js/sales-daily-reminder.js'));

        foreach (['x-cloak', 'aria-modal="true"', 'aria-labelledby', '@keydown.escape.window', 'trapFocus'] as $contract) {
            $this->assertStringContainsString($contract, $view.$script);
        }
        $this->assertStringContainsString('dismissPromise', $script);
        $this->assertStringContainsString('AbortController', $script);
        $this->assertStringContainsString('actionPending', $script);
        $this->assertStringContainsString('conflictOpen()', $view);
        $this->assertStringContainsString('reminder_dismiss_failed', $script);
        $this->assertStringContainsString('window.oasisToast', $script);
        $this->assertStringContainsString('window.location.assign(destination.toString())', $script);
    }

    private function salesContext(): array
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Solo Project', 'is_active' => true]);

        return [$branch, $project, $this->sales($branch, $project, 'Solo Sales')];
    }

    private function sales(Branch $branch, LeadMaster $project, string $name): User
    {
        $sales = $this->user('sales', $branch, $name);
        $sales->assignedProjects()->attach($project, ['is_primary' => true]);

        return $sales;
    }

    private function user(string $slug, ?Branch $branch = null, ?string $name = null): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'is_superadmin' => $slug === 'superadmin']);

        return User::factory()->create([
            'name' => $name ?? ucfirst($slug),
            'role_id' => $role->id,
            'branch_id' => $branch?->id,
            'password_changed_at' => now(),
        ]);
    }

    private function lead(User $sales, LeadMaster $project): SalesLead
    {
        $source = LeadSource::where('is_active', true)->firstOrFail();

        return SalesLead::create([
            'branch_id' => $project->branch_id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_source_id' => $source->id,
            'lead_date' => now()->toDateString(),
            'customer_name' => 'Lead Hari Ini',
            'source_name_snapshot' => $source->name,
            'created_by' => $sales->id,
        ]);
    }

    private function agenda(User $sales, LeadMaster $project, array $overrides = []): ContentItem
    {
        return ContentItem::create(array_merge([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'item_type' => 'agenda',
            'visibility' => 'personal',
            'title' => 'Agenda Hari Ini',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'sales_activity_category' => 'Follow-up',
            'scheduled_date' => now()->toDateString(),
            'start_date' => now()->toDateString(),
            'deadline_date' => now()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'duration_minutes' => 60,
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'sales_project_id' => $project->id,
            'created_by' => $sales->id,
        ], $overrides));
    }
}
