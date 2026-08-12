<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\User;
use App\Services\OptimisticLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SalesRoleWorkspaceScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_hierarchy_resolves_each_primary_role_from_canonical_mapping(): void
    {
        [$manager, $supervisor, $coordinatorA, $coordinatorB, $sales1, $sales2, $sales3] = $this->hierarchy();

        $managerPage = $this->actingAs($manager)->get(route('sales-pocketbook.index'))->assertOk();
        foreach ([$supervisor, $coordinatorA, $coordinatorB, $sales1, $sales2, $sales3] as $member) {
            $managerPage->assertSee($member->name);
        }
        $managerPage->assertSee('Hierarki Tim Sales');

        $this->actingAs($supervisor)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('Monitoring Tim')
            ->assertSee($coordinatorA->name)->assertSee($coordinatorB->name)
            ->assertSee($sales1->name)->assertSee($sales2->name)->assertSee($sales3->name);

        $this->actingAs($coordinatorA)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('Buku Saku Sales')->assertSee($sales1->name)->assertSee($sales2->name)->assertDontSee($sales3->name);
        $this->actingAs($coordinatorB)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('Buku Saku Sales')->assertSee($sales3->name)->assertDontSee($sales1->name)->assertDontSee($sales2->name);
    }

    public function test_sales_shared_workspace_is_own_only_without_team_selectors_or_foreign_mutation(): void
    {
        [, , , , $sales1, $sales2] = $this->hierarchy();
        $own = $this->agenda($sales1, 'AGENDA_SALES_OWN');
        $foreign = $this->agenda($sales2, 'AGENDA_SALES_FOREIGN');

        $response = $this->actingAs($sales1)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertViewIs('crm.sales-pocketbook.sales-agenda')
            ->assertSee('Agenda Saya')->assertSee($own->title)->assertDontSee($foreign->title)
            ->assertDontSee('Semua Sales')->assertDontSee('Semua Koordinator');
        $response->assertSee('crm-pagination', false);

        $token = app(OptimisticLockService::class)->token($foreign);
        $this->actingAs($sales1)->patch(route('sales-agendas.update', $foreign), [
            'activity_result' => 'Tidak boleh',
            'expected_updated_at' => $token,
        ])->assertForbidden();
        $this->actingAs($sales1)->post(route('sales-agendas.reschedule', $foreign), [
            'scheduled_date' => now()->addDay()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'expected_updated_at' => $token,
        ])->assertForbidden();

        $export = $this->actingAs($sales1)->get(route('sales-agendas.export'))->assertOk();
        $path = $export->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($path)->getActiveSheet();
        $this->assertSame(2, $sheet->getHighestDataRow());
        $this->assertSame($own->title, $sheet->getCell('F2')->getValue());
        @unlink($path);
    }

    public function test_role_views_keep_exact_labels_canonical_dates_and_read_only_team_agendas(): void
    {
        [, $supervisor, $coordinator, , $sales] = $this->hierarchy();
        $agenda = $this->agenda($sales, 'AGENDA_READ_ONLY');

        $coordinatorPage = $this->actingAs($coordinator)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('Buku Saku Sales')->assertSee('date-wrapper', false);
        $supervisorPage = $this->actingAs($supervisor)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('Monitoring Tim')->assertSee('date-wrapper', false);
        foreach ([$coordinator, $supervisor] as $viewer) {
            $this->actingAs($viewer)->patch(route('sales-agendas.update', $agenda), [
                'activity_result' => 'Tidak boleh',
                'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
            ])->assertForbidden();
        }

        foreach (['coordinator-leads.blade.php', 'supervisor-monitoring.blade.php'] as $view) {
            $source = file_get_contents(resource_path("views/crm/sales-pocketbook/{$view}"));
            $this->assertStringNotContainsString('type="date"', $source);
            $this->assertStringContainsString('<x-crm.date-field', $source);
            $this->assertStringContainsString('<x-crm.page-header', $source);
        }
    }

    private function hierarchy(): array
    {
        $branch = Branch::create(['name' => 'Magelang', 'code' => 'MGL', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Magelang Residence', 'is_active' => true]);
        $manager = $this->user('manager', 'Manager Magelang', $branch);
        $supervisor = $this->user('supervisor', 'SPV Magelang', $branch, $manager);
        $coordinatorA = $this->user('sales_coordinator', 'Koordinator A', $branch, $supervisor);
        $coordinatorB = $this->user('sales_coordinator', 'Koordinator B', $branch, $supervisor);
        $sales1 = $this->user('sales', 'Sales 1', $branch);
        $sales2 = $this->user('sales', 'Sales 2', $branch, $manager);
        $sales3 = $this->user('sales', 'Sales 3', $branch);

        foreach ([$sales1, $sales2, $sales3] as $sales) {
            $sales->assignedProjects()->attach($project, ['is_primary' => true, 'is_active' => true]);
        }
        foreach ([[$coordinatorA, $sales1], [$coordinatorA, $sales2], [$coordinatorB, $sales3]] as [$coordinator, $sales]) {
            SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id]);
        }

        return [$manager, $supervisor, $coordinatorA, $coordinatorB, $sales1, $sales2, $sales3];
    }

    private function user(string $role, string $name, Branch $branch, ?User $supervisor = null): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'role_id' => Role::where('slug', $role)->value('id'),
            'branch_id' => $branch->id,
            'supervisor_user_id' => $supervisor?->id,
            'password_changed_at' => now(),
        ]);
        $user->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => true, 'can_sync' => true]]);

        return $user;
    }

    private function agenda(User $sales, string $title): ContentItem
    {
        $project = $sales->assignedProjects()->firstOrFail();

        return ContentItem::create([
            'branch_id' => $project->branch_id,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => $title,
            'scheduled_date' => now()->toDateString(),
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
    }
}
