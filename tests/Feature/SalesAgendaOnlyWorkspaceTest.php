<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\User;
use App\Services\NavigationService;
use App\Services\SalesPocketbookOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SalesAgendaOnlyWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_planning_is_not_an_active_route_or_navigation_destination(): void
    {
        $sales = $this->user('sales');

        $this->assertFalse(Route::has('sales-daily-plans.index'));
        $this->assertFalse(Route::has('sales-daily-plans.store'));
        $this->assertFalse(Route::has('sales-daily-plans.revise'));
        $this->assertFalse(Route::has('sales-daily-plan-items.complete'));

        $this->actingAs($sales)->get('/buku-saku-sales/daily-plans')->assertNotFound();

        $labels = collect(app(NavigationService::class)->forUser($sales))
            ->flatMap(fn (array $group) => $group['children'])
            ->pluck('label')
            ->all();

        $this->assertSame(['Buku Saku Sales'], $labels);
    }

    public function test_sales_workspace_uses_buku_saku_agendas_without_daily_planning_markup(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $agenda = ContentItem::create([
            'branch_id' => $branch->id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => 'Agenda Buku Saku',
            'scheduled_date' => '2026-10-28',
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);

        $response = $this->actingAs($sales)->get(route('sales-agendas.index'));

        $response->assertOk()
            ->assertSee($agenda->title)
            ->assertSee('Tambah Agenda')
            ->assertDontSee('Daily Planning')
            ->assertDontSee('Kertas Agenda Harian')
            ->assertDontSee('420 menit');
    }

    public function test_coordinator_sees_only_buku_saku_agendas_owned_by_current_team_sales(): void
    {
        [$branch, $project, $teamSales] = $this->salesContext();
        $otherSales = $this->sales($branch, $project, 'Sales Lain');
        $coordinator = $this->user('sales_coordinator', $branch, 'Koordinator');
        $teamSales->update(['supervisor_user_id' => $coordinator->id]);
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $teamSales->id,
            'is_active' => true,
        ]);

        $teamAgenda = $this->agenda($teamSales, $project, 'Agenda Sales Team');
        $otherAgenda = $this->agenda($otherSales, $project, 'Agenda Sales Lain');

        $response = $this->actingAs($coordinator)->get(route('sales-pocketbook.index', [
            'tab' => 'agenda',
            'period' => 'custom',
            'date_from' => '2026-07-10',
            'date_to' => '2026-07-10',
        ]));

        $response->assertOk()
            ->assertSee($teamAgenda->title)
            ->assertDontSee($otherAgenda->title)
            ->assertDontSee('Daily Plan Sales Tim')
            ->assertDontSee('Kertas Agenda Harian');
        $this->assertArrayNotHasKey('dailyPlans', $response->viewData());
    }

    public function test_agenda_only_workspace_changelog_is_unique_and_visible(): void
    {
        $title = 'Buku Saku Sales Menjadi Ruang Kerja Agenda Utama';
        $superadmin = $this->user('superadmin');

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->actingAs($superadmin)->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText($title);
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
        $sales->assignedProjects()->attach($project, ['is_primary' => true, 'is_active' => true]);
        app(SalesPocketbookOnboardingService::class)->skip($sales);

        return $sales;
    }

    private function user(string $role, ?Branch $branch = null, ?string $name = null): User
    {
        return User::factory()->create([
            'name' => $name ?? ucfirst($role),
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'branch_id' => $branch?->id,
            'password_changed_at' => now(),
        ]);
    }

    private function agenda(User $sales, LeadMaster $project, string $title): ContentItem
    {
        return ContentItem::create([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => $title,
            'scheduled_date' => '2026-07-10',
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
    }
}
