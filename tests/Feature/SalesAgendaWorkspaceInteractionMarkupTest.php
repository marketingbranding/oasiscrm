<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesAgendaDaySubmission;
use App\Models\User;
use App\Services\SalesPocketbookOnboardingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAgendaWorkspaceInteractionMarkupTest extends TestCase
{
    use RefreshDatabase;

    public function test_week_navigation_uses_week_controls_without_a_date_picker(): void
    {
        $sales = $this->sales();
        $today = CarbonImmutable::today(config('app.timezone'))->toDateString();

        $this->actingAs($sales)
            ->get(route('sales-agendas.index'))
            ->assertOk()
            ->assertSee('data-week-previous', false)
            ->assertSee('data-week-next', false)
            ->assertDontSee('sales-week-navigation-picker', false)
            ->assertDontSee('sales-agenda-week-date', false)
            ->assertDontSee('Pilih minggu', false)
            ->assertSee('data-week-day="'.$today.'"', false)
            ->assertSee('data-week-day-today="true"', false)
            ->assertDontSee('name="plan_date"', false);
    }

    public function test_week_navigation_exposes_keyboard_addressable_controls(): void
    {
        $sales = $this->sales();

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => '2026-11-08']))
            ->assertOk()
            ->assertSee('aria-label="Navigasi minggu agenda"', false)
            ->assertSee('data-week-previous', false)
            ->assertSee('data-week-next', false)
            ->assertDontSee('aria-label="Pilih minggu"', false)
            ->assertSee('role="list"', false)
            ->assertSee('role="listitem"', false);
    }

    public function test_week_navigation_keeps_day_links_as_semantic_links_inside_list_items(): void
    {
        $sales = $this->sales();

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => '2026-11-08']))
            ->assertOk()
            ->assertSee('<li class="sales-week-navigation-day-item" role="listitem">', false)
            ->assertSee('<a href="', false)
            ->assertDontSee('class="sales-week-navigation-day" role="listitem"', false);
    }

    public function test_mobile_week_navigation_keeps_touch_targets_without_horizontal_scrolling(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertStringContainsString('grid-template-columns: repeat(7, minmax(44px, 1fr));', $css);
        $this->assertStringContainsString('overflow-x: visible;', $css);
        $this->assertStringContainsString('width: calc(100% + 40px);', $css);
        $this->assertStringContainsString(".sales-week-navigation-day[data-week-day-today='true']", $css);
        $this->assertStringContainsString('.sales-submitted-row-main', $css);
    }

    public function test_receipt_mode_does_not_hide_the_buku_saku_shell(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertStringNotContainsString('.sales-pocketbook-workspace:has(.is-receipt-mode) .sales-reference-header', $css);
        $this->assertStringNotContainsString('.sales-pocketbook-workspace:has(.is-receipt-mode) .sales-pocketbook-tabs', $css);
        $this->assertStringContainsString('.sales-receipt-canvas', $css);
    }

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

    public function test_mobile_modal_transitions_cover_shared_modal_and_agenda_sheet(): void
    {
        $sales = $this->sales();
        $css = file_get_contents(resource_path('css/app.css'));

        $html = $this->actingAs($sales)
            ->get(route('sales-agendas.index'))
            ->assertOk()
            ->getContent();

        $this->assertIsString($css);
        $this->assertStringContainsString('x-transition:enter="crm-modal-mobile-transition-enter"', $html);
        $this->assertStringContainsString('x-transition:leave-end="sales-sheet-transition-leave-end"', $html);
        $this->assertStringContainsString('.crm-modal-backdrop:not(.crm-modal-backdrop--right).crm-modal-mobile-transition-enter-start', $css);
        $this->assertStringContainsString('.sales-sheet-transition-enter-start', $css);
        $this->assertStringContainsString('body.sales-personal-page .sales-sheet-transition-enter-start .sales-bottom-sheet', $css);
        $this->assertStringContainsString('transform: translateY(100%);', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        $this->assertStringContainsString('.sales-sheet-transition-enter,', $css);
    }

    public function test_weekend_add_action_opens_the_agenda_sheet_without_receipt_preview(): void
    {
        $sales = $this->sales();

        $html = $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => '2026-11-01']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('openAgendaSheet($event)', $html);
        $this->assertStringNotContainsString('openAgendaPreview($event)', $html);
        $this->assertStringNotContainsString('data-agenda-receipt-preview', $html);
        $this->assertStringContainsString('aria-labelledby="agenda-weekend-title"', $html);
        $this->assertStringContainsString('Tambah Rencana', $html);
        $this->assertStringContainsString('Tambahkan Rencana', $html);

        $weekendPartial = file_get_contents(resource_path('views/crm/sales-pocketbook/partials/agenda-weekend-list.blade.php'));

        $this->assertIsString($weekendPartial);
        $this->assertStringContainsString('@if($project)', $weekendPartial);
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

    public function test_draft_receipt_exposes_edit_delete_actions_and_submitted_receipt_hides_them(): void
    {
        $sales = $this->sales();
        $project = $sales->assignedProjects()->firstOrFail();
        $agenda = ContentItem::create([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => 'Rencana Draft',
            'sales_activity_category' => 'Follow-up',
            'scheduled_date' => today()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '08:30',
            'duration_minutes' => 30,
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);

        $draft = $this->actingAs($sales)->get(route('sales-agendas.index'));

        $draft->assertOk()
            ->assertSee('data-agenda-edit', false)
            ->assertSee('Edit', false)
            ->assertSee('data-agenda-delete data-agenda-id', false)
            ->assertSee('Hapus', false)
            ->assertSee('sales-agenda-delete-confirm', false)
            ->assertSee((string) $agenda->id, false);

        SalesAgendaDaySubmission::create([
            'owner_user_id' => $sales->id,
            'scheduled_date' => today()->toDateString(),
            'target_minutes' => 480,
            'normal_minutes_at_submission' => 30,
            'submitted_at' => now(),
        ]);

        $submitted = $this->actingAs($sales)->get(route('sales-agendas.index'));

        $submitted->assertOk()
            ->assertDontSee('data-agenda-edit', false)
            ->assertDontSee('data-agenda-delete data-agenda-id', false)
            ->assertSee('Terkirim ke Koordinator');
    }

    public function test_processed_agendas_in_an_unsubmitted_receipt_do_not_expose_draft_actions(): void
    {
        $sales = $this->sales();
        $project = $sales->assignedProjects()->firstOrFail();

        foreach (['done', 'cancelled', 'rescheduled'] as $status) {
            ContentItem::create([
                'branch_id' => $project->branch_id,
                'project_name' => $project->project_name,
                'sales_project_id' => $project->id,
                'item_type' => 'agenda',
                'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
                'visibility' => 'personal',
                'title' => 'Agenda '.ucfirst($status),
                'sales_activity_category' => 'Follow-up',
                'scheduled_date' => today()->toDateString(),
                'start_time' => '08:00',
                'end_time' => '08:30',
                'duration_minutes' => 30,
                'status' => $status,
                'owner_user_id' => $sales->id,
                'created_by' => $sales->id,
            ]);
        }

        $this->actingAs($sales)
            ->get(route('sales-agendas.index'))
            ->assertOk()
            ->assertDontSee('data-agenda-edit', false)
            ->assertDontSee('data-agenda-delete data-agenda-id', false);
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
