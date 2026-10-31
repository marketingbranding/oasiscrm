<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesPocketbookOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAgendaWorkspaceInteractionMarkupTest extends TestCase
{
    use RefreshDatabase;

    public function test_agenda_bottom_sheet_has_accessible_controls_and_required_fields(): void
    {
        $sales = $this->sales();

        $html = $this->actingAs($sales)
            ->get(route('sales-agendas.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('x-data="salesAgendaWorkspace()"', $html);
        $this->assertStringContainsString('openAgendaSheet($event)', $html);
        $this->assertStringContainsString('id="sales-agenda-create-sheet"', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('@keydown="handleSheetKeydown($event)"', $html);
        $this->assertStringContainsString('name="sales_activity_category"', $html);
        $this->assertStringContainsString('name="title"', $html);
        $this->assertStringContainsString('name="start_time"', $html);
        $this->assertStringContainsString('name="duration_minutes"', $html);
        $this->assertStringContainsString('name="end_time"', $html);
        $this->assertStringContainsString('name="location"', $html);
    }

    public function test_lead_workspace_keeps_a_labelled_add_action(): void
    {
        $sales = $this->sales();

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['tab' => 'leads']))
            ->assertOk()
            ->assertSee('+ Tambah lead')
            ->assertSee('aria-label="Status Lead Saya"', false);
    }

    private function sales(): User
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

        return $sales;
    }
}
