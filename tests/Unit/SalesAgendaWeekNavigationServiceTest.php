<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesAgendaWeekNavigationService;
use App\Services\SalesPocketbookOnboardingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAgendaWeekNavigationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_week_starts_on_monday_and_exposes_per_day_plan_states(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agendaAt($sales, $project, '2026-11-03', '09:00', '10:00', 'Agenda planned', 'planned');
        $this->agendaAt($sales, $project, '2026-11-04', '09:00', '10:00', 'Agenda selesai', 'done');
        $this->agendaAt($sales, $project, '2026-11-05', '09:00', '10:00', 'Agenda dibatalkan', 'cancelled');
        $this->agendaAt($sales, $project, '2026-11-06', '09:00', '10:00', 'Agenda ditunda', 'rescheduled');
        $this->agendaAt($sales, $project, '2026-11-07', '09:00', '10:00', 'Agenda terkonfirmasi', 'confirmed');

        $week = app(SalesAgendaWeekNavigationService::class)->for($sales, CarbonImmutable::parse('2026-11-08'));

        $this->assertSame('2026-11-02', $week['start']->toDateString());
        $this->assertSame('2026-11-08', $week['end']->toDateString());
        $this->assertSame('2026-11-01', $week['previous_date']->toDateString());
        $this->assertSame('2026-11-15', $week['next_date']->toDateString());
        $this->assertCount(7, $week['days']);

        $days = collect($week['days'])->keyBy(fn (array $day): string => $day['date']->toDateString());

        $this->assertFalse($days['2026-11-02']['has_plan']);
        $this->assertTrue($days['2026-11-03']['has_plan']);
        $this->assertTrue($days['2026-11-03']['has_incomplete_agenda']);
        $this->assertTrue($days['2026-11-04']['has_plan']);
        $this->assertFalse($days['2026-11-04']['has_incomplete_agenda']);
        $this->assertFalse($days['2026-11-05']['has_plan']);
        $this->assertFalse($days['2026-11-06']['has_plan']);
        $this->assertTrue($days['2026-11-07']['has_plan']);
        $this->assertTrue($days['2026-11-07']['has_incomplete_agenda']);
    }

    public function test_week_indicators_ignore_agendas_outside_the_users_scope(): void
    {
        [$sales] = $this->salesContext();
        $closedBranch = Branch::create(['name' => 'Cabang Tertutup', 'code' => 'CT', 'is_active' => true]);
        $closedProject = LeadMaster::create(['branch_id' => $closedBranch->id, 'project_name' => 'Proyek Tertutup', 'is_active' => true]);
        $this->agendaAt($sales, $closedProject, '2026-11-03', '09:00', '10:00', 'Agenda tersembunyi', 'planned');

        $week = app(SalesAgendaWeekNavigationService::class)->for($sales, CarbonImmutable::parse('2026-11-03'));
        $tuesday = collect($week['days'])->first(fn (array $day): bool => $day['date']->toDateString() === '2026-11-03');

        $this->assertFalse($tuesday['has_plan']);
        $this->assertFalse($tuesday['has_incomplete_agenda']);
    }

    public function test_week_indicators_ignore_unassigned_projects_in_an_accessible_branch(): void
    {
        [$sales, $project] = $this->salesContext();
        $hiddenProject = LeadMaster::create([
            'branch_id' => $project->branch_id,
            'project_name' => 'Proyek Tanpa Penugasan',
            'is_active' => true,
        ]);
        $this->agendaAt($sales, $hiddenProject, '2026-11-03', '09:00', '10:00', 'Agenda proyek tersembunyi', 'planned');

        $week = app(SalesAgendaWeekNavigationService::class)->for($sales, CarbonImmutable::parse('2026-11-03'));
        $tuesday = collect($week['days'])->first(fn (array $day): bool => $day['date']->toDateString() === '2026-11-03');

        $this->assertFalse($tuesday['has_plan']);
        $this->assertFalse($tuesday['has_incomplete_agenda']);
    }

    public function test_week_indicators_include_projectless_agendas_in_an_accessible_branch(): void
    {
        [$sales, $project] = $this->salesContext();
        $agenda = $this->agendaAt($sales, $project, '2026-11-03', '09:00', '10:00', 'Agenda tanpa proyek', 'planned');
        $agenda->update(['sales_project_id' => null]);

        $week = app(SalesAgendaWeekNavigationService::class)->for($sales, CarbonImmutable::parse('2026-11-03'));
        $tuesday = collect($week['days'])->first(fn (array $day): bool => $day['date']->toDateString() === '2026-11-03');

        $this->assertTrue($tuesday['has_plan']);
        $this->assertTrue($tuesday['has_incomplete_agenda']);
    }

    /** @return array{0: User, 1: LeadMaster} */
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

    private function agendaAt(User $sales, LeadMaster $project, string $date, string $start, string $end, string $title, string $status): ContentItem
    {
        return ContentItem::create([
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
            'duration_minutes' => 60,
            'status' => $status,
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
    }
}
