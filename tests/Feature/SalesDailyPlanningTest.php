<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesDailyPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_planning_routes_are_removed_from_the_active_sales_workspace(): void
    {
        $sales = $this->user('sales');

        $this->assertFalse(Route::has('sales-daily-plans.index'));
        $this->assertFalse(Route::has('sales-daily-plans.store'));
        $this->assertFalse(Route::has('sales-daily-plan-items.complete'));
        $this->actingAs($sales)->get('/buku-saku-sales/daily-plans')->assertNotFound();
    }

    public function test_sales_can_create_a_buku_saku_agenda_without_a_daily_plan(): void
    {
        [$branch, $project, $sales] = $this->context();

        $this->actingAs($sales)->post(route('sales-agendas.store'), [
            'scheduled_date' => '2026-10-20',
            'sales_activity_category' => 'Canvassing',
            'title' => 'Kunjungan calon konsumen',
            'location' => 'Project P2',
        ])->assertRedirect();

        $this->assertDatabaseHas('content_items', [
            'branch_id' => $branch->id,
            'sales_project_id' => $project->id,
            'owner_user_id' => $sales->id,
            'title' => 'Kunjungan calon konsumen',
            'daily_plan_id' => null,
        ]);
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

        $lead = SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-10-20',
            'customer_name' => 'Historis Tanpa Nomor',
            'created_by' => $sales->id,
        ]);

        $this->assertNull($lead->fresh()->phone);
    }

    public function test_agenda_reminders_are_deduplicated_without_daily_plan_state(): void
    {
        [$branch, $project, $sales] = $this->context();
        $agenda = ContentItem::create([
            'branch_id' => $branch->id,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => 'Pengingat',
            'scheduled_date' => '2026-10-20',
            'status' => 'planned',
            'duration_minutes' => 60,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);

        Carbon::setTestNow('2026-10-20 08:46:00');
        Artisan::call('oasis:sales-agenda-reminders');
        Artisan::call('oasis:sales-agenda-reminders');

        $this->assertDatabaseHas('sales_agenda_notification_deliveries', [
            'content_item_id' => $agenda->id,
            'notification_type' => 'sales_agenda_upcoming',
        ]);
        $this->assertDatabaseCount('sales_agenda_notification_deliveries', 1);

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

    private function user(string $slug, ?Branch $branch = null, ?string $name = null): User
    {
        return User::factory()->create([
            'name' => $name ?? $slug,
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'branch_id' => $branch?->id,
            'password_changed_at' => now(),
        ]);
    }
}
