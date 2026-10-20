<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesDailyPlan;
use App\Models\SalesLead;
use App\Models\SalesLeadTarget;
use App\Models\User;
use App\Services\OptimisticLockService;
use App\Services\SalesDailyPlanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesDailyPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_can_create_draft_add_agendas_and_submit_only_at_four_hundred_twenty_minutes(): void
    {
        [$branch, $project, $sales] = $this->context();
        $this->actingAs($sales)->post(route('sales-daily-plans.store'), ['plan_date' => '2026-10-20'])->assertRedirect();
        $plan = SalesDailyPlan::sole();

        $this->actingAs($sales)->post(route('sales-daily-plans.agendas.store', $plan), [
            'sales_activity_category' => 'Canvassing', 'duration_minutes' => 210, 'task_detail' => 'Blok A',
            'sales_project_id' => $project->id, 'expected_updated_at' => app(OptimisticLockService::class)->token($plan),
        ])->assertRedirect();
        $plan->refresh();
        $this->assertSame('09:00:00', ContentItem::sole()->start_time);

        $this->actingAs($sales)->post(route('sales-daily-plans.agendas.store', $plan), [
            'sales_activity_category' => 'Follow-up', 'duration_minutes' => 209, 'task_detail' => 'Telepon',
            'expected_updated_at' => app(OptimisticLockService::class)->token($plan),
        ])->assertRedirect();
        $plan->refresh();
        $this->assertSame(419, (int) $plan->agendas->sum('duration_minutes'));
        $this->actingAs($sales)->post(route('sales-daily-plans.submit', $plan), [
            'expected_updated_at' => app(OptimisticLockService::class)->token($plan),
        ])->assertSessionHasErrors('plan');

        $second = $plan->agendas()->reorder()->orderByDesc('id')->first();
        $this->actingAs($sales)->patch(route('sales-daily-plans.agendas.update', [$plan, $second]), [
            'sales_activity_category' => $second->sales_activity_category, 'duration_minutes' => 210,
            'task_detail' => $second->task_detail, 'expected_updated_at' => app(OptimisticLockService::class)->token($plan),
        ])->assertRedirect();
        $plan->refresh();
        $this->assertSame(420, (int) $plan->agendas->sum('duration_minutes'));
        $this->actingAs($sales)->post(route('sales-daily-plans.submit', $plan), [
            'expected_updated_at' => app(OptimisticLockService::class)->token($plan),
        ])->assertRedirect();
        $this->assertDatabaseHas('sales_daily_plans', ['id' => $plan->id, 'status' => 'submitted']);
        $this->assertSame('13:30:00', $plan->fresh()->agendas->last()->start_time);
    }

    public function test_coordinator_can_correct_submitted_team_planning_but_not_submit_or_create_for_sales(): void
    {
        [$branch, $project, $sales] = $this->context();
        $coordinator = $this->user('sales_coordinator', $branch, 'Coordinator');
        SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id, 'is_active' => true]);
        $plan = SalesDailyPlan::create(['branch_id' => $branch->id, 'sales_user_id' => $sales->id, 'plan_date' => '2026-10-20', 'status' => 'submitted', 'lock_version' => 0]);
        $agenda = ContentItem::create(['branch_id' => $branch->id, 'sales_project_id' => $project->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'visibility' => 'personal', 'title' => 'Agenda', 'task_detail' => 'Detail', 'scheduled_date' => '2026-10-20', 'status' => 'planned', 'duration_minutes' => 420, 'sort_order' => 1, 'owner_user_id' => $sales->id, 'daily_plan_id' => $plan->id, 'created_by' => $sales->id]);
        app(SalesDailyPlanService::class)->recalculate($plan, $sales);
        $plan->refresh();

        $this->actingAs($coordinator)->patch(route('sales-daily-plans.agendas.update', [$plan, $agenda]), [
            'sales_activity_category' => 'Canvassing', 'duration_minutes' => 420, 'task_detail' => 'Koreksi',
            'expected_updated_at' => app(OptimisticLockService::class)->token($plan),
        ])->assertRedirect();
        $this->assertDatabaseHas('sales_daily_plans', ['id' => $plan->id, 'status' => 'submitted']);
        $this->actingAs($coordinator)->post(route('sales-daily-plans.submit', $plan), ['expected_updated_at' => app(OptimisticLockService::class)->token($plan)])->assertForbidden();
        $this->actingAs($coordinator)->post(route('sales-daily-plans.store'), ['plan_date' => '2026-10-21'])->assertForbidden();
    }

    public function test_target_achievement_uses_sales_owner_and_missing_target_is_unset(): void
    {
        [$branch, $project, $sales] = $this->context();
        SalesLead::create(['branch_id' => $branch->id, 'project_id' => $project->id, 'sales_user_id' => $sales->id, 'lead_date' => '2026-10-02', 'customer_name' => 'A', 'created_by' => $sales->id]);
        SalesLead::create(['branch_id' => $branch->id, 'project_id' => $project->id, 'sales_user_id' => $sales->id, 'lead_date' => '2026-09-30', 'customer_name' => 'B', 'created_by' => $sales->id]);
        $this->actingAs($sales)->get(route('sales-daily-plans.index', ['date' => '2026-10-20']))->assertOk()->assertSee('Belum ditetapkan')->assertSee('Lead Aktual')->assertSee('N/A');

        $superadmin = $this->user('superadmin', $branch, 'Superadmin');
        SalesLeadTarget::create(['sales_user_id' => $sales->id, 'period_year' => 2026, 'period_month' => 10, 'lead_target' => 4, 'created_by' => $superadmin->id, 'updated_by' => $superadmin->id]);
        $this->actingAs($sales)->get(route('sales-daily-plans.index', ['date' => '2026-10-20']))->assertOk()->assertSee('25%');
    }

    public function test_interactive_lead_form_requires_phone_but_storage_remains_nullable(): void
    {
        [$branch, $project, $sales] = $this->context();
        $this->actingAs($sales)->post(route('sales-leads.store'), [
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-10-20',
            'customer_name' => 'Tanpa Nomor',
            'source' => 'Referral',
            'platform' => 'WhatsApp',
            'campaign_name' => 'Referral',
            'current_status' => 'no_response',
            'operation_uuid' => (string) Str::uuid(),
        ])->assertSessionHasErrors('phone');

        $lead = SalesLead::create(['branch_id' => $branch->id, 'project_id' => $project->id, 'sales_user_id' => $sales->id, 'lead_date' => '2026-10-20', 'customer_name' => 'Historis Tanpa Nomor', 'created_by' => $sales->id]);
        $this->assertNull($lead->fresh()->phone);
    }

    public function test_stale_daily_plan_update_returns_conflict(): void
    {
        [$branch, $project, $sales] = $this->context();
        $plan = SalesDailyPlan::create(['branch_id' => $branch->id, 'sales_user_id' => $sales->id, 'plan_date' => '2026-10-20', 'status' => 'draft', 'lock_version' => 0]);
        $agenda = ContentItem::create(['branch_id' => $branch->id, 'sales_project_id' => $project->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'visibility' => 'personal', 'title' => 'Agenda', 'task_detail' => 'Detail', 'scheduled_date' => '2026-10-20', 'status' => 'planned', 'duration_minutes' => 420, 'sort_order' => 1, 'owner_user_id' => $sales->id, 'daily_plan_id' => $plan->id, 'created_by' => $sales->id]);
        $oldToken = app(OptimisticLockService::class)->token($plan);
        $plan->update(['lock_version' => 1]);

        $this->actingAs($sales)->withHeaders(['Accept' => 'application/json'])->patch(route('sales-daily-plans.agendas.update', [$plan, $agenda]), [
            'sales_activity_category' => 'Canvassing', 'duration_minutes' => 420, 'task_detail' => 'Stale', 'expected_updated_at' => $oldToken,
        ])->assertStatus(409);
    }

    public function test_agenda_reminders_are_deduplicated_and_overdue_skips_completed_items(): void
    {
        [$branch, $project, $sales] = $this->context();
        $plan = SalesDailyPlan::create(['branch_id' => $branch->id, 'sales_user_id' => $sales->id, 'plan_date' => '2026-10-20', 'status' => 'submitted', 'lock_version' => 0]);
        $agenda = ContentItem::create(['branch_id' => $branch->id, 'sales_project_id' => $project->id, 'item_type' => 'agenda', 'agenda_type' => ContentItem::SALES_AGENDA_TYPE, 'visibility' => 'personal', 'title' => 'Pengingat', 'task_detail' => 'Detail', 'scheduled_date' => '2026-10-20', 'status' => 'planned', 'duration_minutes' => 60, 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'sort_order' => 1, 'owner_user_id' => $sales->id, 'daily_plan_id' => $plan->id, 'created_by' => $sales->id]);
        Carbon::setTestNow('2026-10-20 08:46:00');
        Artisan::call('oasis:sales-agenda-reminders');
        Artisan::call('oasis:sales-agenda-reminders');
        $this->assertDatabaseHas('sales_agenda_notification_deliveries', ['content_item_id' => $agenda->id, 'notification_type' => 'sales_agenda_upcoming']);
        $this->assertDatabaseCount('sales_agenda_notification_deliveries', 1);

        $agenda->update(['status' => 'done']);
        Carbon::setTestNow('2026-10-20 10:16:00');
        Artisan::call('oasis:sales-agenda-reminders');
        $this->assertDatabaseMissing('sales_agenda_notification_deliveries', ['content_item_id' => $agenda->id, 'notification_type' => 'sales_agenda_overdue']);
        Carbon::setTestNow();
    }

    private function context(): array
    {
        $branch = Branch::create(['name' => 'Phase 2', 'code' => 'P2'.Str::random(4), 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Project P2', 'is_active' => true]);
        $sales = $this->user('sales', $branch, 'Sales P2');
        $sales->assignedProjects()->attach($project, ['is_primary' => true, 'is_active' => true]);

        return [$branch, $project, $sales];
    }

    private function user(string $slug, Branch $branch, string $name): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();

        return User::factory()->create(['name' => $name, 'role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
    }
}
