<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesAgendaDaySubmission;
use App\Models\User;
use App\Services\OptimisticLockService;
use App\Services\SalesAgendaReceiptService;
use App\Services\SalesPocketbookOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SalesAgendaReceiptAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_agenda_requires_start_and_end_time(): void
    {
        [$sales, $project] = $this->salesContext();

        $this->actingAs($sales)
            ->post(route('sales-agendas.store'), [
                'scheduled_date' => '2026-10-31',
                'sales_activity_category' => 'Follow-up',
                'title' => 'Agenda tanpa waktu',
            ])
            ->assertSessionHasErrors(['start_time', 'end_time']);

        $this->assertDatabaseCount('content_items', 0);
        $this->assertNotNull($project);
    }

    public function test_owner_can_submit_exact_target_and_new_agenda_becomes_additional(): void
    {
        [$sales, $project] = $this->salesContext();
        $agenda = $this->agenda($sales, $project, 420);

        $this->actingAs($sales)
            ->post(route('sales-agendas.submit'), ['scheduled_date' => '2026-10-31'])
            ->assertRedirect(route('sales-agendas.index'));

        $this->assertDatabaseHas('sales_agenda_day_submissions', [
            'owner_user_id' => $sales->id,
            'target_minutes' => 420,
            'normal_minutes_at_submission' => 420,
        ]);

        $this->actingAs($sales)
            ->post(route('sales-agendas.store'), [
                'scheduled_date' => '2026-10-31',
                'start_time' => '17:00',
                'end_time' => '17:30',
                'sales_activity_category' => 'Follow-up',
                'title' => 'Agenda tambahan',
            ])
            ->assertRedirect(route('sales-agendas.index'));

        $this->assertDatabaseHas('content_items', [
            'title' => 'Agenda tambahan',
            'is_outside_schedule' => true,
        ]);
        $this->assertSame(420, $agenda->fresh()->duration_minutes);
    }

    public function test_submitted_agenda_can_be_completed_and_receive_evidence(): void
    {
        [$sales, $project] = $this->salesContext();
        $agenda = $this->agenda($sales, $project, 60);
        SalesAgendaDaySubmission::create([
            'owner_user_id' => $sales->id,
            'scheduled_date' => $agenda->scheduled_date,
            'target_minutes' => 420,
            'normal_minutes_at_submission' => 420,
            'submitted_at' => now(),
        ]);

        $this->assertFalse(app(SalesAgendaReceiptService::class)->isScheduleEditable($sales, $agenda));
        $this->assertFalse($agenda->fresh()->isFinished());
    }

    public function test_owner_can_defer_an_agenda_to_the_next_day_as_a_linked_copy(): void
    {
        [$sales, $project] = $this->salesContext();
        $agenda = $this->agenda($sales, $project, 60);

        $this->actingAs($sales)
            ->post(route('sales-agendas.reschedule', $agenda), [
                'scheduled_date' => '2026-11-01',
                'start_time' => '09:00',
                'end_time' => '10:00',
                'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
            ])
            ->assertRedirect(route('sales-agendas.index'));

        $this->assertSame('rescheduled', $agenda->fresh()->status);
        $this->assertDatabaseHas('content_items', [
            'rescheduled_from_id' => $agenda->id,
            'scheduled_date' => '2026-11-01 00:00:00',
            'status' => 'planned',
        ]);
    }

    public function test_other_sales_user_cannot_complete_or_upload_to_an_agenda(): void
    {
        Storage::fake('agenda_evidence');
        [$sales, $project] = $this->salesContext();
        $otherSales = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'sales')->value('id'),
            'branch_id' => $project->branch_id,
            'password_changed_at' => now(),
        ]);
        $agenda = $this->agenda($sales, $project, 60);

        $this->actingAs($otherSales)
            ->patch(route('sales-agendas.update', $agenda), [
                'activity_result' => 'Tidak boleh.',
                'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
            ])
            ->assertForbidden();

        $this->actingAs($otherSales)
            ->post(route('sales-agendas.evidence.store', $agenda), [
                'photo' => UploadedFile::fake()->image('forbidden.jpg'),
            ])
            ->assertForbidden();
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

    private function agenda(User $sales, LeadMaster $project, int $duration): ContentItem
    {
        $agenda = ContentItem::create([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => 'Agenda awal',
            'sales_activity_category' => 'Follow-up',
            'scheduled_date' => '2026-10-31',
            'start_time' => '09:00',
            'end_time' => sprintf('%02d:00', 9 + intdiv($duration, 60)),
            'duration_minutes' => $duration,
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
        $agenda->assignees()->sync([$sales->id]);

        return $agenda;
    }
}
