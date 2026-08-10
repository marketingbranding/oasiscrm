<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\OptimisticLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesPocketbookTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_sales_shared_url_renders_agenda_only_with_simplified_fields(): void
    {
        [, , $sales] = $this->salesContext();

        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('Agenda Saya')
            ->assertSee('Catat dan selesaikan agenda sales Anda.')
            ->assertSee('name="scheduled_date"', false)
            ->assertSee('name="title"', false)
            ->assertSee('name="location"', false)
            ->assertSee('name="activity_result"', false)
            ->assertDontSee('Lead Saya')
            ->assertDontSee('Input Lead')
            ->assertDontSee('Sinkronisasi')
            ->assertDontSee('name="branch_id"', false)
            ->assertDontSee('name="project_id"', false)
            ->assertDontSee('name="sales_user_id"', false)
            ->assertDontSee('name="start_time"', false)
            ->assertDontSee('name="end_time"', false)
            ->assertDontSee('name="sales_activity_category"', false);
    }

    public function test_sales_without_project_sees_exact_blocking_message_and_no_form(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $sales = $this->user('sales', $branch);

        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('Proyek utama belum ditentukan. Hubungi admin untuk menetapkan proyek utama.')
            ->assertDontSee('name="scheduled_date"', false);
    }

    public function test_sales_creates_agenda_from_simplified_payload(): void
    {
        [$branch, $project, $sales] = $this->salesContext();

        $this->actingAs($sales)->post(route('sales-agendas.store'), [
            'scheduled_date' => '2026-07-10',
            'title' => 'Follow-up Konsumen',
            'location' => 'Kantor pemasaran',
            'activity_result' => 'Konsumen tertarik.',
        ])->assertRedirect(route('sales-agendas.index'))
            ->assertSessionHas('success', 'Agenda sales berhasil ditambahkan.');

        $agenda = ContentItem::sole();
        $this->assertSame($branch->id, $agenda->branch_id);
        $this->assertSame($project->id, $agenda->sales_project_id);
        $this->assertSame($sales->id, $agenda->owner_user_id);
        $this->assertSame('done', $agenda->status);
        $this->assertSame('Konsumen tertarik.', $agenda->activity_result);
        $this->assertTrue($agenda->assignees->contains($sales));
    }

    public function test_sales_agenda_requires_only_simplified_operational_fields(): void
    {
        [, , $sales] = $this->salesContext();

        $this->actingAs($sales)->post(route('sales-agendas.store'), [
            'scheduled_date' => '2026-07-10',
            'title' => 'Kunjungan lokasi',
            'location' => '',
            'activity_result' => '',
        ])->assertSessionDoesntHaveErrors();

        $this->actingAs($sales)->post(route('sales-agendas.store'), [])->assertSessionHasErrors([
            'scheduled_date', 'title',
        ]);
    }

    public function test_sales_can_complete_own_agenda_but_not_another_sales_agenda(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $other = $this->sales($branch, $project, 'Sales Lain');
        $own = $this->agenda($sales, $project);
        $foreign = $this->agenda($other, $project);

        $this->actingAs($sales)->patch(route('sales-agendas.update', $own), [
            'activity_result' => 'Selesai.',
            'expected_updated_at' => app(OptimisticLockService::class)->token($own),
        ])->assertRedirect(route('sales-agendas.index'));
        $this->assertSame('done', $own->fresh()->status);

        $this->actingAs($sales)->patch(route('sales-agendas.update', $foreign), [
            'activity_result' => 'Tidak boleh.',
            'expected_updated_at' => app(OptimisticLockService::class)->token($foreign),
        ])->assertForbidden();
    }

    public function test_coordinator_lead_workspace_contains_only_assigned_team_leads(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $other = $this->sales($branch, $project, 'Sales Lain');
        $coordinator = $this->user('sales_coordinator', $branch, 'Koordinator');
        $coordinator->currentCoordinatorSales()->attach($sales, ['is_active' => true]);
        $visible = $this->lead($sales, $project, 'Lead Tim');
        $this->lead($other, $project, 'Lead Bukan Tim');

        $response = $this->actingAs($coordinator)->get(route('sales-pocketbook.index'))->assertOk();
        $this->assertContains($visible->id, $response->viewData('leads')->pluck('id'));
        $this->assertNotContains('Lead Bukan Tim', $response->viewData('leads')->pluck('customer_name'));
    }

    public function test_manager_keeps_branch_monitoring_and_outside_branch_is_denied(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $lead = $this->lead($sales, $project, 'Lead Cabang');
        $manager = $this->user('manager', $branch);
        $otherBranch = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);

        $this->actingAs($manager)->get(route('sales-pocketbook.index'))->assertOk()->assertSee($lead->customer_name);
        $this->actingAs($manager)->get(route('sales-pocketbook.index', ['branch_id' => $otherBranch->id]))->assertForbidden();
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
            'title' => 'Agenda Test',
            'scheduled_date' => '2026-07-10',
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
    }

    private function lead(User $sales, LeadMaster $project, string $name): SalesLead
    {
        return SalesLead::create([
            'branch_id' => $project->branch_id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-07-10',
            'customer_name' => $name,
            'created_by' => $sales->id,
        ]);
    }
}
