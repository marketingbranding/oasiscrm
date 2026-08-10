<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\CoordinatorLeadPushService;
use App\Services\OptimisticLockService;
use App\Services\SalesLeadSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SupervisorBukuSakuReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private Branch $branch;

    private LeadMaster $project;

    private SalesLead $lead;

    private ContentItem $agenda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'sheet_id' => 'sheet-solo', 'is_active' => true]);
        $this->project = LeadMaster::create(['branch_id' => $this->branch->id, 'project_name' => 'Solo Project', 'is_active' => true]);
        $role = Role::query()->where('slug', 'supervisor')->firstOrFail();
        $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('slug', [
            'sales_pocketbook.manage_own',
            'sales_pocketbook.manage_team',
            'sales_pocketbook.manage_assigned',
            'sales_pocketbook.manage_all',
            'sales_pocketbook.sync',
            'sales_pocketbook.reconcile',
        ])->pluck('id'));
        $this->supervisor = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $this->branch->id,
            'password_changed_at' => now(),
        ]);
        $this->supervisor->branches()->syncWithoutDetaching([
            $this->branch->id => ['can_view' => true, 'can_edit' => true, 'can_sync' => true],
        ]);
        $this->lead = SalesLead::create([
            'branch_id' => $this->branch->id,
            'project_id' => $this->project->id,
            'sales_user_id' => $this->supervisor->id,
            'lead_date' => '2026-08-10',
            'customer_name' => 'Lead Supervisor',
            'created_by' => $this->supervisor->id,
        ]);
        $this->agenda = ContentItem::create([
            'branch_id' => $this->branch->id,
            'sales_project_id' => $this->project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => 'Agenda Supervisor',
            'scheduled_date' => '2026-08-10',
            'status' => 'planned',
            'owner_user_id' => $this->supervisor->id,
            'created_by' => $this->supervisor->id,
        ]);
    }

    public function test_supervisor_cannot_write_leads_or_push_coordinator_sync_with_stale_permissions(): void
    {
        $push = Mockery::mock(CoordinatorLeadPushService::class);
        $push->shouldNotReceive('push');
        $this->app->instance(CoordinatorLeadPushService::class, $push);

        $this->actingAs($this->supervisor)->post(route('sales-leads.store'))->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('sales-leads.edit', $this->lead))->assertForbidden();
        $this->actingAs($this->supervisor)->put(route('sales-leads.update', $this->lead))->assertForbidden();
        $this->actingAs($this->supervisor)->post(route('coordinator-leads.sync'))->assertForbidden();
    }

    public function test_supervisor_cannot_write_sales_agendas_with_stale_permissions(): void
    {
        $this->actingAs($this->supervisor)->post(route('sales-agendas.store'))->assertForbidden();
        $this->actingAs($this->supervisor)->patch(route('sales-agendas.update', $this->agenda), [
            'activity_result' => 'Tidak boleh.',
            'expected_updated_at' => app(OptimisticLockService::class)->token($this->agenda),
        ])->assertForbidden();
    }

    public function test_supervisor_cannot_pull_view_status_or_reconcile_with_stale_permissions(): void
    {
        $sync = Mockery::mock(SalesLeadSyncService::class);
        $sync->shouldNotReceive('sync');
        $this->app->instance(SalesLeadSyncService::class, $sync);
        $parameters = ['branch_id' => $this->branch->id];

        $this->actingAs($this->supervisor)->post(route('sales-pocketbook.lifecycle-sync'), $parameters)->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('sales-pocketbook.lifecycle-sync.status', $parameters))->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('sales-pocketbook.lifecycle-reconciliations.index', $parameters))->assertForbidden();
    }
}
