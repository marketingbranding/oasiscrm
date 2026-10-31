<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesAgendaDaySubmission;
use App\Models\User;
use App\Services\SalesPocketbookOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAgendaWorkspaceRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_agenda_renders_receipt_target_and_add_action(): void
    {
        [$sales, $project] = $this->salesContext();

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-31']))
            ->assertOk()
            ->assertSee('Agenda Buku Saku')
            ->assertSee('Lead Saya')
            ->assertSee('0 / 420 menit')
            ->assertSee('+ Tambah agenda')
            ->assertSee('Belum ada agenda')
            ->assertSee($project->project_name);
    }

    public function test_submitted_receipt_renders_status_and_extra_work_state(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agenda($sales, $project, 420);
        $extra = $this->agenda($sales, $project, 30, true);
        SalesAgendaDaySubmission::create([
            'owner_user_id' => $sales->id,
            'scheduled_date' => '2026-10-31',
            'target_minutes' => 420,
            'normal_minutes_at_submission' => 420,
            'submitted_at' => now(),
        ]);

        $this->actingAs($sales)
            ->get(route('sales-agendas.index', ['work_date' => '2026-10-31']))
            ->assertOk()
            ->assertSee('Terkirim ke Koordinator')
            ->assertSee('Waktu, judul, kategori, dan lokasi agenda terjadwal tidak dapat diubah.')
            ->assertSee('Agenda tambahan · di luar target')
            ->assertSee($extra->title)
            ->assertDontSee('Sobek &amp; kirim ke Koordinator', false);
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
            ->assertSee('Input Lead Hari Ini')
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

    private function agenda(User $sales, LeadMaster $project, int $duration, bool $outsideSchedule = false): ContentItem
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
            'scheduled_date' => '2026-10-31',
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
}
