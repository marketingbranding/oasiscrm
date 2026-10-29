<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\OptimisticLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesPocketbookScopeMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_cannot_open_work_planner_direct_url(): void
    {
        $context = $this->context();

        $this->actingAs($context['sales'])
            ->get(route('content-calendar.index'))
            ->assertForbidden();
    }

    public function test_sales_cannot_edit_another_sales_lead_by_direct_url(): void
    {
        $context = $this->context();

        $this->actingAs($context['sales'])
            ->get(route('sales-leads.edit', $context['foreignLead']))
            ->assertForbidden();
    }

    public function test_coordinator_can_create_update_and_export_current_team_scope(): void
    {
        $context = $this->context();

        $this->actingAs($context['coordinator'])
            ->post(route('sales-leads.store'), [
                'branch_id' => $context['branch']->id,
                'project_id' => $context['project']->id,
                'sales_user_id' => $context['sales']->id,
                'lead_date' => today()->toDateString(),
                'customer_name' => 'Lead Tim Baru',
                'phone' => '081234567890',
                'source' => 'Online',
                'platform' => 'Instagram',
                'campaign_name' => 'Live',
                'current_status' => 'no_response',
                'operation_uuid' => fake()->uuid(),
            ])
            ->assertRedirect();

        $teamLead = SalesLead::query()->where('customer_name', 'Lead Tim Baru')->sole();

        $this->actingAs($context['coordinator'])
            ->get(route('sales-leads.edit', $teamLead))
            ->assertOk();

        $this->actingAs($context['coordinator'])
            ->put(route('sales-leads.update', $teamLead), [
                'branch_id' => $context['branch']->id,
                'project_id' => $context['project']->id,
                'sales_user_id' => $context['sales']->id,
                'lead_date' => today()->toDateString(),
                'customer_name' => 'Lead Tim Diperbarui',
                'phone' => '081234567890',
                'source' => 'Online',
                'platform' => 'Instagram',
                'campaign_name' => 'Live',
                'current_status' => 'no_response',
                'expected_updated_at' => app(OptimisticLockService::class)->token($teamLead),
            ])
            ->assertRedirect();

        $this->actingAs($context['coordinator'])
            ->get(route('coordinator-leads.export', ['period' => 'month']))
            ->assertOk();
    }

    public function test_buku_saku_does_not_render_manual_spreadsheet_sync_controls(): void
    {
        $context = $this->context();

        $this->actingAs($context['coordinator'])
            ->get(route('sales-pocketbook.index'))
            ->assertOk()
            ->assertDontSee('SYNC KE SPREADSHEET')
            ->assertDontSee('Sinkronisasi Lead')
            ->assertDontSee('Rekonsiliasi');
    }

    public function test_coordinator_supervisor_admin_and_branch_manager_have_monitoring_views_without_sync_controls(): void
    {
        $context = $this->context();

        foreach ([$context['coordinator'], $context['supervisor'], $context['admin'], $context['branchManager']] as $actor) {
            $this->actingAs($actor)
                ->get(route('sales-pocketbook.index', ['tab' => 'agenda']))
                ->assertOk()
                ->assertSee('Buku Saku Sales')
                ->assertDontSee('SYNC KE SPREADSHEET')
                ->assertDontSee('Sinkronisasi Lead')
                ->assertDontSee('Rekonsiliasi');
        }
    }

    public function test_coordinator_cannot_edit_a_lead_outside_current_team(): void
    {
        $context = $this->context();

        $this->actingAs($context['coordinator'])
            ->get(route('sales-leads.edit', $context['foreignLead']))
            ->assertForbidden();
    }

    public function test_coordinator_cannot_export_a_forged_sales_user_outside_current_team(): void
    {
        $context = $this->context();

        $this->actingAs($context['coordinator'])
            ->get(route('coordinator-leads.export', [
                'period' => 'month',
                'sales_id' => $context['foreignSales']->id,
            ]))
            ->assertForbidden();
    }

    public function test_supervisor_cannot_mutate_buku_saku_leads(): void
    {
        $context = $this->context();

        $this->actingAs($context['supervisor'])
            ->get(route('sales-leads.edit', $context['lead']))
            ->assertForbidden();

        $this->actingAs($context['supervisor'])
            ->patch(route('sales-leads.lifecycle-status.update', $context['lead']), [
                'status' => 'discussion',
                'operation_uuid' => fake()->uuid(),
            ])
            ->assertForbidden();
    }

    public function test_admin_and_branch_manager_cannot_mutate_buku_saku_leads(): void
    {
        $context = $this->context();

        foreach ([$context['admin'], $context['branchManager']] as $actor) {
            $this->actingAs($actor)
                ->get(route('sales-leads.edit', $context['lead']))
                ->assertForbidden();

            $this->actingAs($actor)
                ->patch(route('sales-leads.lifecycle-status.update', $context['lead']), [
                    'status' => 'discussion',
                    'operation_uuid' => fake()->uuid(),
                ])
                ->assertForbidden();
        }
    }

    public function test_supervisor_admin_and_branch_manager_cannot_update_sales_agendas(): void
    {
        $context = $this->context();

        foreach ([$context['supervisor'], $context['admin'], $context['branchManager']] as $actor) {
            $this->actingAs($actor)
                ->patch(route('sales-agendas.update', $context['foreignAgenda']), [
                    'activity_result' => 'Tidak boleh',
                    'expected_updated_at' => app(OptimisticLockService::class)->token($context['foreignAgenda']),
                ])
                ->assertForbidden();
        }

        $this->assertSame('planned', $context['foreignAgenda']->fresh()->status);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Solo Project', 'is_active' => true]);
        $sales = $this->user('sales', $branch, 'Sales Tim');
        $foreignSales = $this->user('sales', $branch, 'Sales Lain');
        $coordinator = $this->user('sales_coordinator', $branch, 'Koordinator Tim');
        $outsiderCoordinator = $this->user('sales_coordinator', $branch, 'Koordinator Lain');
        $supervisor = $this->user('supervisor', $branch, 'Supervisor Tim');
        $admin = $this->user('admin', $branch, 'Admin Cabang');
        $branchManager = $this->user('branch_manager', $branch, 'Branch Manager');

        foreach ([$sales, $foreignSales] as $salesUser) {
            $salesUser->assignedProjects()->attach($project, ['is_primary' => true, 'is_active' => true]);
        }
        SalesCoordinatorSales::create([
            'coordinator_user_id' => $coordinator->id,
            'sales_user_id' => $sales->id,
        ]);

        $foreignAgenda = $this->agenda($foreignSales, $project, 'Agenda Sales Lain');

        return [
            'branch' => $branch,
            'project' => $project,
            'sales' => $sales,
            'foreignSales' => $foreignSales,
            'coordinator' => $coordinator,
            'supervisor' => $supervisor,
            'admin' => $admin,
            'branchManager' => $branchManager,
            'lead' => $this->lead($sales, $project, 'Lead Tim'),
            'foreignLead' => $this->lead($foreignSales, $project, 'Lead Sales Lain'),
            'foreignAgenda' => $foreignAgenda,
        ];
    }

    private function user(string $roleSlug, Branch $branch, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
    }

    private function lead(User $sales, LeadMaster $project, string $name): SalesLead
    {
        return SalesLead::create([
            'branch_id' => $project->branch_id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today(),
            'customer_name' => $name,
            'phone' => '081234567890',
            'source' => 'Instagram',
            'platform' => 'WhatsApp',
            'campaign_name' => 'Follow-up',
            'current_status' => 'no_response',
            'created_by' => $sales->id,
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
            'scheduled_date' => today(),
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
    }
}
