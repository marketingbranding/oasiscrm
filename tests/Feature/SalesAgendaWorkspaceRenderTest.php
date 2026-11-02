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

class SalesAgendaWorkspaceRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_agenda_renders_receipt_target_and_add_action(): void
    {
        [$sales, $project] = $this->salesContext();

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('sales-personal-page', false)
            ->assertSee('sales-reference-shell', false)
            ->assertSee('Buku Saku Saya')
            ->assertSee('Agenda')
            ->assertSee('Lead Saya')
            ->assertSee('0 / 480 menit')
            ->assertSee('Jam kerja')
            ->assertSee('07:30–17:00')
            ->assertSee('target hari ini')
            ->assertSee('TARGET HARIAN')
            ->assertSee('480 MENIT')
            ->assertDontSee('420 MENIT')
            ->assertSee('Belum ada rencana hari ini')
            ->assertSee('sales-reference-bottom-action', false)
            ->assertSee('+ Tambah rencana')
            ->assertSee('Belum ada agenda')
            ->assertSee($project->project_name);
    }

    public function test_populated_receipt_keeps_date_navigation_with_exact_date_links(): void
    {
        [$sales, $project] = $this->salesContext();
        $today = CarbonImmutable::today(config('app.timezone'));
        $yesterday = $today->subDay();

        $this->agendaAt($sales, $project, $yesterday->toDateString(), '09:00', '10:00', 'Agenda kemarin');
        $this->agendaAt($sales, $project, $today->toDateString(), '09:00', '10:00', 'Agenda hari ini');
        $this->agendaAt($sales, $project, $today->addDay()->toDateString(), '09:00', '10:00', 'Agenda rencana');

        $response = $this->actingAs($sales)
            ->get(route('sales-agendas.index', [
                'tab' => 'agenda',
                'work_date' => $yesterday->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Kemarin')
            ->assertSee('Hari ini')
            ->assertSee('Rencana')
            ->assertSee('Agenda kemarin')
            ->assertDontSee('Agenda hari ini')
            ->assertDontSee('Agenda rencana');

        $response
            ->assertSee('href="'.e(route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $yesterday->toDateString()])).'"', false)
            ->assertSee('href="'.e(route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $today->toDateString()])).'"', false)
            ->assertSee('href="'.e(route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $today->addDay()->toDateString()])).'"', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_receipt_formats_open_slot_start_time_as_clock_time(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agenda($sales, $project, 420, false, '2026-10-30');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('16:00–17:00 · kosong 60 menit')
            ->assertDontSee('960–17:00 · kosong 60 menit');
    }

    public function test_open_slot_guidance_uses_effective_minutes_after_the_break(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agendaAt($sales, $project, '2026-10-30', '09:00', '10:00', 'Agenda pagi');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('10:00–17:00 · kosong 330 menit');
    }

    public function test_break_start_preview_uses_effective_remaining_minutes(): void
    {
        [$sales] = $this->salesContext();

        $this->withSession(['_old_input' => [
            'start_time' => '12:00',
            'duration_minutes' => '60',
        ]])->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('Selesai 13:00 · sisa 480 menit');
    }

    public function test_weekend_agenda_renders_free_plan_without_target_or_break_row(): void
    {
        [$sales] = $this->salesContext();

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-11-01']))
            ->assertOk()
            ->assertSee('Akhir pekan')
            ->assertSee('AKHIR PEKAN')
            ->assertSee('Tanpa target')
            ->assertDontSee('TARGET HARIAN')
            ->assertDontSee('420 menit')
            ->assertDontSee('Istirahat');
    }

    public function test_break_agenda_renders_distinct_status_label(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agendaAt($sales, $project, '2026-10-30', '11:30', '13:30', 'Agenda melintasi istirahat');

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('Agenda jam istirahat')
            ->assertSee('is-break', false);
    }

    public function test_submitted_outside_break_agenda_renders_both_status_labels(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agendaAt($sales, $project, '2026-10-30', '07:30', '17:00', 'Agenda utama');
        $this->agendaAt($sales, $project, '2026-10-30', '12:00', '13:00', 'Agenda tambahan istirahat', true);
        SalesAgendaDaySubmission::create([
            'owner_user_id' => $sales->id,
            'scheduled_date' => '2026-10-30',
            'target_minutes' => 480,
            'normal_minutes_at_submission' => 480,
            'submitted_at' => now(),
        ]);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('Agenda tambahan · di luar target · Agenda jam istirahat')
            ->assertSee('sales-submitted-row is-break', false);
    }

    public function test_flexible_agenda_row_does_not_render_placeholder_clock_or_zero_minutes(): void
    {
        [$sales, $project] = $this->salesContext();
        $agenda = ContentItem::create([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => 'Agenda fleksibel',
            'sales_activity_category' => 'Follow-up',
            'scheduled_date' => '2026-10-30',
            'start_time' => null,
            'end_time' => null,
            'duration_minutes' => null,
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
        $agenda->assignees()->sync([$sales->id]);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('Fleksibel')
            ->assertDontSee('--:--')
            ->assertDontSee('0m</strong>', false);
    }

    public function test_mobile_agenda_form_renders_slot_controls_and_sticky_action_contract(): void
    {
        [$sales] = $this->salesContext();

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('data-agenda-slot-form', false)
            ->assertSee('data-duration-option="30"', false)
            ->assertSee('data-duration-option="60"', false)
            ->assertSee('data-duration-option="90"', false)
            ->assertSee('data-duration-option="120"', false)
            ->assertSee('Custom', false)
            ->assertSee('Selesai', false)
            ->assertSee('sisa', false)
            ->assertSee('sales-receipt-form-actions', false)
            ->assertSee('Tambahkan ke struk');
    }

    public function test_mobile_empty_agenda_requires_receipt_preview_before_add_form(): void
    {
        [$sales] = $this->salesContext();

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('openAgendaPreview', false)
            ->assertSee('data-agenda-receipt-preview', false)
            ->assertSee('data-receipt-preview-add', false)
            ->assertSee('openAgendaSheet', false);
    }

    public function test_submitted_receipt_renders_status_and_extra_work_state(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agendaAt($sales, $project, '2026-10-30', '07:30', '17:00', 'Agenda utama');
        $extra = $this->agenda($sales, $project, 30, true, '2026-10-30');
        SalesAgendaDaySubmission::create([
            'owner_user_id' => $sales->id,
            'scheduled_date' => '2026-10-30',
            'target_minutes' => 480,
            'normal_minutes_at_submission' => 480,
            'submitted_at' => now(),
        ]);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-30']))
            ->assertOk()
            ->assertSee('Terkirim ke Koordinator')
            ->assertSee('Waktu, judul, kategori, dan lokasi agenda terjadwal tidak dapat diubah.')
            ->assertSee('Agenda tambahan · di luar target')
            ->assertSee($extra->title)
            ->assertDontSee('Sobek &amp; kirim ke Koordinator', false);
    }

    public function test_submitted_weekend_receipt_keeps_weekend_badge_and_no_target_footer(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agendaAt($sales, $project, '2026-11-01', '09:00', '09:30', 'Agenda utama');
        SalesAgendaDaySubmission::create([
            'owner_user_id' => $sales->id,
            'scheduled_date' => '2026-11-01',
            'target_minutes' => 0,
            'normal_minutes_at_submission' => 30,
            'submitted_at' => now(),
        ]);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-11-01']))
            ->assertOk()
            ->assertSee('Terkirim ke Koordinator')
            ->assertSee('AKHIR PEKAN')
            ->assertSee('TOTAL 30 MENIT')
            ->assertSee('TANPA TARGET')
            ->assertSee('+ Tambah rencana akhir pekan')
            ->assertSee('sales-agenda-create-sheet', false)
            ->assertSee('Simpan agenda tambahan');
    }

    public function test_lead_saya_renders_counts_cards_and_add_action(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['tab' => 'leads']))
            ->assertOk()
            ->assertSee('Belum selesai')
            ->assertSee('Selesai')
            ->assertSee('+ Tambah lead')
            ->assertSee('Tambah lead')
            ->assertDontSee('Struk Agenda');
    }

    private function salesContext(): array
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

        return [$sales, $project];
    }

    private function agenda(User $sales, LeadMaster $project, int $duration, bool $outsideSchedule = false, string $scheduledDate = '2026-10-31'): ContentItem
    {
        $agenda = ContentItem::create([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => $outsideSchedule ? 'Agenda tambahan' : 'Agenda utama',
            'sales_activity_category' => 'Follow-up',
            'scheduled_date' => $scheduledDate,
            'start_time' => '09:00',
            'end_time' => $duration === 420 ? '16:00' : '16:30',
            'duration_minutes' => $duration,
            'status' => 'planned',
            'is_outside_schedule' => $outsideSchedule,
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
        $agenda->assignees()->sync([$sales->id]);

        return $agenda;
    }

    private function agendaAt(User $sales, LeadMaster $project, string $date, string $start, string $end, string $title, bool $outsideSchedule = false): ContentItem
    {
        $agenda = ContentItem::create([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => $title,
            'sales_activity_category' => 'Follow-up',
            'scheduled_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'duration_minutes' => ((int) substr($end, 0, 2) * 60 + (int) substr($end, 3, 2)) - ((int) substr($start, 0, 2) * 60 + (int) substr($start, 3, 2)),
            'status' => 'planned',
            'is_outside_schedule' => $outsideSchedule,
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
        $agenda->assignees()->sync([$sales->id]);

        return $agenda;
    }
}
