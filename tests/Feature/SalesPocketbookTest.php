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
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesPocketbookTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_sales_shared_url_keeps_agenda_form_and_lead_input_route_opens_same_workflow(): void
    {
        [$branch, $project, $sales] = $this->salesContext();

        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('Agenda Saya')
            ->assertSee('Lead Saya')
            ->assertDontSee('name="sales_user_id"', false);

        $this->actingAs($sales)->get(route('sales-leads.create'))->assertRedirect(route('sales-pocketbook.index', ['input' => 1]));
        $this->actingAs($sales)->get(route('sales-pocketbook.index', ['input' => 1]))->assertOk()
            ->assertSee('Input Lead Hari Ini')
            ->assertSee('name="sales_user_id" value="'.$sales->id.'"', false)
            ->assertSee('value="'.$project->id.'"', false)
            ->assertSee('value="'.$branch->id.'"', false);
    }

    public function test_primary_sales_creates_lead_for_self_and_cannot_spoof_sales_owner(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $otherSales = $this->user('sales', $branch);
        $otherSales->assignedProjects()->attach($project, ['is_primary' => false, 'is_active' => true]);

        $this->actingAs($sales)->post(route('sales-leads.store'), $this->leadData($branch, $project, $otherSales))->assertRedirect();

        $this->assertDatabaseHas('sales_leads', ['customer_name' => 'Lead Test', 'sales_user_id' => $sales->id]);
        $this->assertDatabaseMissing('sales_leads', ['customer_name' => 'Lead Test', 'sales_user_id' => $otherSales->id]);
    }

    public function test_sales_workspace_has_exact_category_options_and_displays_historical_values_safely(): void
    {
        [, $project, $sales] = $this->salesContext();
        $this->agenda($sales, $project);
        $historical = $this->agenda($sales, $project);
        $historical->update(['title' => 'Agenda Survey Lama', 'sales_activity_category' => 'Survey Lokasi']);

        $response = $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk();

        $response->assertSeeInOrder(array_map(
            static fn (string $category): string => 'value="'.$category.'"',
            ContentItem::SALES_ACTIVITY_CATEGORIES,
        ), false)->assertDontSee('value="Survey Lokasi"', false)
            ->assertSee('Agenda Test')
            ->assertSee('Agenda Survey Lama')
            ->assertSee('Survey Lokasi');
    }

    public function test_sales_without_project_sees_exact_blocking_message_and_no_form(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $sales = $this->user('sales', $branch);

        $this->actingAs($sales)->get(route('sales-pocketbook.index'))->assertOk()
            ->assertSee('Proyek utama belum ditentukan. Hubungi admin untuk menetapkan proyek utama.')
            ->assertDontSee('name="scheduled_date"', false);
    }

    public function test_sales_creates_planned_agenda_without_result_then_completes_it(): void
    {
        [$branch, $project, $sales] = $this->salesContext();

        $this->actingAs($sales)->post(route('sales-agendas.store'), [
            'scheduled_date' => '2026-07-10',
            'sales_activity_category' => 'Cek Lokasi',
            'title' => 'Follow-up Konsumen',
            'location' => 'Kantor pemasaran',
            'activity_result' => null,
        ])->assertRedirect(route('sales-agendas.index'))
            ->assertSessionHas('success', 'Agenda sales berhasil ditambahkan.');

        $agenda = ContentItem::sole();
        $this->assertSame($branch->id, $agenda->branch_id);
        $this->assertSame($project->id, $agenda->sales_project_id);
        $this->assertSame($sales->id, $agenda->owner_user_id);
        $this->assertSame('planned', $agenda->status);
        $this->assertNull($agenda->activity_result);
        $this->assertSame('Cek Lokasi', $agenda->sales_activity_category);
        $this->assertTrue($agenda->assignees->contains($sales));

        $this->actingAs($sales)->patch(route('sales-agendas.update', $agenda), [
            'activity_result' => 'Konsumen tertarik.',
            'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
        ])->assertRedirect(route('sales-agendas.index'));

        $this->assertSame('done', $agenda->fresh()->status);
        $this->assertSame('Konsumen tertarik.', $agenda->fresh()->activity_result);
    }

    #[DataProvider('salesActivityCategoryProvider')]
    public function test_sales_can_store_every_exact_activity_category(string $category): void
    {
        [, , $sales] = $this->salesContext();

        $this->actingAs($sales)->post(route('sales-agendas.store'), [
            'scheduled_date' => '2026-07-10',
            'sales_activity_category' => $category,
            'title' => 'Agenda '.$category,
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame($category, ContentItem::sole()->sales_activity_category);
    }

    public static function salesActivityCategoryProvider(): array
    {
        return array_combine(ContentItem::SALES_ACTIVITY_CATEGORIES, array_map(
            static fn (string $category): array => [$category],
            ContentItem::SALES_ACTIVITY_CATEGORIES,
        ));
    }

    #[DataProvider('invalidSalesActivityCategoryProvider')]
    public function test_sales_rejects_invalid_activity_category(?string $category): void
    {
        [, , $sales] = $this->salesContext();

        $this->actingAs($sales)->post(route('sales-agendas.store'), [
            'scheduled_date' => '2026-07-10',
            'sales_activity_category' => $category,
            'title' => 'Agenda Tidak Valid',
        ])->assertSessionHasErrors('sales_activity_category');

        $this->assertDatabaseCount('content_items', 0);
    }

    public static function invalidSalesActivityCategoryProvider(): array
    {
        return [
            'null' => [null],
            'blank' => [''],
            'placeholder' => ['Pilih kategori'],
            'legacy Survey Lokasi' => ['Survey Lokasi'],
            'random activity' => ['Random Activity'],
            'misspelled Canvassing' => ['Canvassinggg'],
        ];
    }

    public function test_sales_agenda_requires_only_simplified_operational_fields(): void
    {
        [, , $sales] = $this->salesContext();

        $this->actingAs($sales)->post(route('sales-agendas.store'), [
            'scheduled_date' => '2026-07-10',
            'sales_activity_category' => 'Cek Lokasi',
            'title' => 'Kunjungan lokasi',
            'location' => '',
            'activity_result' => '',
        ])->assertSessionDoesntHaveErrors();

        $this->actingAs($sales)->post(route('sales-agendas.store'), [])->assertSessionHasErrors([
            'scheduled_date', 'sales_activity_category', 'title',
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
        $sales->update(['supervisor_user_id' => $coordinator->id]);
        $coordinator->currentCoordinatorSales()->attach($sales, ['is_active' => true]);
        $visible = $this->lead($sales, $project, 'Lead Tim');
        $this->lead($other, $project, 'Lead Bukan Tim');

        $response = $this->actingAs($coordinator)->get(route('sales-pocketbook.index', [
            'period' => 'custom',
            'date_from' => '2026-07-10',
            'date_to' => '2026-07-10',
        ]))->assertOk();
        $this->assertContains($visible->id, $response->viewData('leads')->pluck('id'));
        $this->assertNotContains('Lead Bukan Tim', $response->viewData('leads')->pluck('customer_name'));
    }

    public function test_manager_keeps_branch_monitoring_and_outside_branch_is_denied(): void
    {
        [$branch, $project, $sales] = $this->salesContext();
        $lead = $this->lead($sales, $project, 'Lead Cabang');
        $manager = $this->user('manager', $branch);
        $coordinator = $this->user('sales_coordinator', $branch, 'Koordinator Manager');
        $coordinator->update(['supervisor_user_id' => $manager->id]);
        SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id]);
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
        $sales->assignedProjects()->attach($project, ['is_primary' => true, 'is_active' => true]);

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

    private function leadData(Branch $branch, LeadMaster $project, User $sales): array
    {
        return [
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-07-10',
            'customer_name' => 'Lead Test',
            'phone' => '081234567890',
            'source' => 'Referral',
            'platform' => 'WhatsApp',
            'campaign_name' => 'Referral',
            'current_status' => 'no_response',
            'operation_uuid' => (string) Str::uuid(),
        ];
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
