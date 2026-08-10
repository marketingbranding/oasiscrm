<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesDailyReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesDailyReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sales_shared_url_shows_agenda_only_without_lead_reminder_actions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 08:00:00', config('app.timezone')));
        [, , $sales] = $this->salesContext();

        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('Agenda Saya')
            ->assertDontSee('Input Lead')
            ->assertDontSee('Belum ada lead yang dicatat hari ini.')
            ->assertDontSee('data-lead-input-url', false);
    }

    public function test_daily_reminder_service_still_counts_sales_agenda(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 09:00:00', config('app.timezone')));
        [, $project, $sales] = $this->salesContext();
        $this->agenda($sales, $project);

        $state = app(SalesDailyReminderService::class)->state($sales);
        $this->assertSame(1, $state['todayAgendaCount']);
        $this->assertSame(0, $state['missingAgendaResultCount']);
    }

    public function test_dismissal_remains_idempotent_and_user_scoped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 10:00:00', config('app.timezone')));
        [$branch, $project, $sales] = $this->salesContext();
        $other = $this->sales($branch, $project, 'Sales Lain');
        $payload = ['reminder_key' => SalesDailyReminderService::KEY];

        $this->actingAs($sales)->postJson(route('sales-reminders.dismiss'), $payload)->assertOk();
        $this->actingAs($sales)->postJson(route('sales-reminders.dismiss'), $payload)->assertOk();
        $this->assertDatabaseCount('user_daily_reminder_dismissals', 1);
        $this->assertFalse(app(SalesDailyReminderService::class)->state($sales)['shouldShow']);
        $this->assertTrue(app(SalesDailyReminderService::class)->state($other)['shouldShow']);
    }

    public function test_no_project_uses_exact_blocking_text_without_agenda_form(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $sales = $this->user('sales', $branch);

        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('Proyek utama belum ditentukan. Hubungi admin untuk menetapkan proyek utama.')
            ->assertDontSee('name="scheduled_date"', false);
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
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'is_superadmin' => false]);

        return User::factory()->create(['name' => $name ?? ucfirst($slug), 'role_id' => $role->id, 'branch_id' => $branch?->id, 'password_changed_at' => now()]);
    }

    private function agenda(User $sales, LeadMaster $project): ContentItem
    {
        return ContentItem::create([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => 'Agenda Hari Ini',
            'scheduled_date' => now()->toDateString(),
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
    }
}
