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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SalesAgendaReceiptAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_agenda_preserves_today_and_future_workspace_dates_in_redirects(): void
    {
        [$sales] = $this->salesContext();
        $today = CarbonImmutable::today(config('app.timezone'));

        foreach ([$today, $today->addDay()] as $date) {
            $this->actingAs($sales)
                ->post(route('sales-agendas.store'), [
                    'scheduled_date' => $date->toDateString(),
                    'start_time' => '08:00',
                    'end_time' => '09:00',
                    'sales_activity_category' => 'Follow-up',
                    'title' => 'Agenda '.$date->toDateString(),
                ])
                ->assertRedirect(route('sales-agendas.index', ['work_date' => $date->toDateString()]));
        }
    }

    public function test_adding_agenda_from_receipt_preview_returns_to_the_resumable_draft(): void
    {
        [$sales] = $this->salesContext();
        $date = CarbonImmutable::today(config('app.timezone'))->toDateString();

        $this->actingAs($sales)
            ->post(route('sales-agendas.store'), [
                'scheduled_date' => $date,
                'start_time' => '08:00',
                'end_time' => '09:00',
                'sales_activity_category' => 'Follow-up',
                'title' => 'Agenda dari struk',
                'receipt_context' => '1',
            ])
            ->assertRedirect(route('sales-agendas.index', [
                'work_date' => $date,
                'resume_receipt' => '1',
            ]));

        $this->assertDatabaseHas('content_items', [
            'title' => 'Agenda dari struk',
            'status' => 'planned',
        ]);
    }

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

    public function test_new_agenda_rejects_duration_that_is_not_a_multiple_of_five(): void
    {
        [$sales] = $this->salesContext();

        $this->actingAs($sales)
            ->post(route('sales-agendas.store'), [
                'scheduled_date' => '2026-10-29',
                'start_time' => '09:00',
                'end_time' => '09:07',
                'sales_activity_category' => 'Follow-up',
                'title' => 'Agenda durasi tidak valid',
            ])
            ->assertSessionHasErrors(['end_time']);

        $this->assertDatabaseMissing('content_items', ['title' => 'Agenda durasi tidak valid']);
    }

    public function test_new_agenda_accepts_a_valid_custom_duration_from_the_resolved_end_time(): void
    {
        [$sales] = $this->salesContext();

        $this->actingAs($sales)
            ->post(route('sales-agendas.store'), [
                'scheduled_date' => '2026-10-29',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'sales_activity_category' => 'Follow-up',
                'title' => 'Agenda custom 180 menit',
                'custom_duration_minutes' => '180',
            ])
            ->assertRedirect(route('sales-agendas.index', ['work_date' => '2026-10-29']))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('content_items', [
            'title' => 'Agenda custom 180 menit',
            'duration_minutes' => 180,
        ]);
    }

    public function test_new_weekday_agenda_can_be_created_during_break_time(): void
    {
        [$sales, $project] = $this->salesContext();
        $this->agendaAt($sales, $project, '2026-10-29', '09:00', '10:00');

        $this->actingAs($sales)
            ->post(route('sales-agendas.store'), [
                'scheduled_date' => '2026-10-29',
                'start_time' => '09:30',
                'end_time' => '10:30',
                'sales_activity_category' => 'Follow-up',
                'title' => 'Agenda tumpang tindih',
            ])
            ->assertSessionHasErrors(['end_time']);

        $this->actingAs($sales)
            ->post(route('sales-agendas.store'), [
                'scheduled_date' => '2026-10-29',
                'start_time' => '12:00',
                'end_time' => '13:00',
                'sales_activity_category' => 'Follow-up',
                'title' => 'Agenda jam istirahat',
            ])
            ->assertRedirect(route('sales-agendas.index', ['work_date' => '2026-10-29']))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('content_items', [
            'title' => 'Agenda jam istirahat',
            'duration_minutes' => 60,
            'is_outside_schedule' => false,
        ]);
    }

    public function test_capacity_uses_effective_minutes_for_a_cross_break_agenda(): void
    {
        [$sales, $project] = $this->salesContext();
        $date = '2026-10-30';
        $this->agendaAt($sales, $project, $date, '07:30', '10:30');
        $this->agendaAt($sales, $project, $date, '10:30', '11:00');
        $this->agendaAt($sales, $project, $date, '13:30', '17:00');

        $this->actingAs($sales)
            ->post(route('sales-agendas.store'), [
                'scheduled_date' => $date,
                'start_time' => '11:00',
                'end_time' => '13:30',
                'sales_activity_category' => 'Follow-up',
                'title' => 'Agenda melintasi istirahat',
            ])
            ->assertRedirect(route('sales-agendas.index', ['work_date' => $date]))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('content_items', [
            'title' => 'Agenda melintasi istirahat',
            'duration_minutes' => 150,
            'is_outside_schedule' => false,
        ]);
    }

    public function test_weekend_agenda_can_be_created_without_weekday_target(): void
    {
        [$sales, $project] = $this->salesContext();

        $this->actingAs($sales)
            ->post(route('sales-agendas.store'), [
                'scheduled_date' => '2026-11-01',
                'start_time' => '20:00',
                'end_time' => '21:00',
                'sales_activity_category' => 'TikTok Live',
                'title' => 'TikTok Live akhir pekan',
            ])
            ->assertRedirect(route('sales-agendas.index', ['work_date' => '2026-11-01']))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('content_items', [
            'title' => 'TikTok Live akhir pekan',
            'scheduled_date' => '2026-11-01 00:00:00',
            'duration_minutes' => 60,
            'is_outside_schedule' => false,
        ]);
    }

    public function test_owner_can_submit_exact_target_and_new_agenda_becomes_additional(): void
    {
        [$sales, $project] = $this->salesContext();
        $agenda = $this->agendaAt($sales, $project, '2026-10-29', '08:00', '17:00');

        $this->actingAs($sales)
            ->post(route('sales-agendas.submit'), ['scheduled_date' => '2026-10-29'])
            ->assertRedirect(route('sales-agendas.index', ['work_date' => '2026-10-29']));

        $this->assertDatabaseHas('sales_agenda_day_submissions', [
            'owner_user_id' => $sales->id,
            'target_minutes' => 480,
            'normal_minutes_at_submission' => 480,
        ]);

        $this->actingAs($sales)
            ->post(route('sales-agendas.store'), [
                'scheduled_date' => '2026-10-29',
                'start_time' => '17:00',
                'end_time' => '17:30',
                'sales_activity_category' => 'Follow-up',
                'title' => 'Agenda tambahan',
            ])
            ->assertRedirect(route('sales-agendas.index', ['work_date' => '2026-10-29']));

        $this->assertDatabaseHas('content_items', [
            'title' => 'Agenda tambahan',
            'is_outside_schedule' => true,
        ]);
        $this->assertSame(540, $agenda->fresh()->duration_minutes);
    }

    public function test_owner_can_edit_a_planned_agenda_before_submission_and_duration_is_recalculated(): void
    {
        [$sales, $project] = $this->salesContext();
        $agenda = $this->agendaAt($sales, $project, '2026-10-29', '09:00', '10:00');

        $this->actingAs($sales)
            ->withHeaders(['Accept' => 'application/json'])
            ->patch(route('sales-agendas.plan.update', $agenda), [
                'sales_activity_category' => 'Canvassing',
                'title' => 'Agenda diperbarui',
                'start_time' => '10:00',
                'end_time' => '12:00',
                'location' => 'Jabung Malang',
                'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
            ])
            ->assertRedirect(route('sales-agendas.index', ['work_date' => '2026-10-29']));

        $this->assertDatabaseHas('content_items', [
            'id' => $agenda->id,
            'title' => 'Agenda diperbarui',
            'sales_activity_category' => 'Canvassing',
            'start_time' => '10:00',
            'end_time' => '12:00',
            'duration_minutes' => 120,
            'location' => 'Jabung Malang',
            'status' => 'planned',
        ]);
    }

    public function test_owner_can_delete_a_planned_agenda_before_submission(): void
    {
        [$sales, $project] = $this->salesContext();
        $agenda = $this->agendaAt($sales, $project, '2026-10-29', '09:00', '10:00');

        $this->actingAs($sales)
            ->withHeaders(['Accept' => 'application/json'])
            ->delete(route('sales-agendas.plan.destroy', $agenda), [
                'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
            ])
            ->assertRedirect(route('sales-agendas.index', ['work_date' => '2026-10-29']));

        $this->assertDatabaseMissing('content_items', ['id' => $agenda->id]);
    }

    public function test_editing_an_agenda_excludes_itself_from_overlap_but_rejects_other_overlap(): void
    {
        [$sales, $project] = $this->salesContext();
        $agenda = $this->agendaAt($sales, $project, '2026-10-29', '09:00', '10:00');
        $other = $this->agendaAt($sales, $project, '2026-10-29', '11:00', '12:00');

        $this->actingAs($sales)
            ->patch(route('sales-agendas.plan.update', $agenda), [
                'sales_activity_category' => 'Follow-up',
                'title' => 'Agenda tetap di slot sendiri',
                'start_time' => '09:00',
                'end_time' => '10:00',
                'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
            ])
            ->assertRedirect(route('sales-agendas.index', ['work_date' => '2026-10-29']));

        $this->actingAs($sales)
            ->patch(route('sales-agendas.plan.update', $agenda->fresh()), [
                'sales_activity_category' => 'Follow-up',
                'title' => 'Agenda bentrok',
                'start_time' => '11:30',
                'end_time' => '12:30',
                'expected_updated_at' => app(OptimisticLockService::class)->token($agenda->fresh()),
            ])
            ->assertSessionHasErrors('end_time');

        $this->assertSame('Agenda tetap di slot sendiri', $agenda->fresh()->title);
        $this->assertSame('11:00', substr((string) $other->fresh()->start_time, 0, 5));
    }

    public function test_submitted_agenda_cannot_be_edited_or_deleted(): void
    {
        [$sales, $project] = $this->salesContext();
        $agenda = $this->agendaAt($sales, $project, '2026-10-29', '08:00', '17:00');
        SalesAgendaDaySubmission::create([
            'owner_user_id' => $sales->id,
            'scheduled_date' => $agenda->scheduled_date,
            'target_minutes' => 480,
            'normal_minutes_at_submission' => 480,
            'submitted_at' => now(),
        ]);

        $this->actingAs($sales)
            ->withHeaders(['Accept' => 'application/json'])
            ->patch(route('sales-agendas.plan.update', $agenda), [
                'sales_activity_category' => 'Canvassing',
                'title' => 'Tidak boleh berubah',
                'start_time' => '08:00',
                'end_time' => '17:00',
                'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
            ])
            ->assertStatus(422);

        $this->actingAs($sales)
            ->withHeaders(['Accept' => 'application/json'])
            ->delete(route('sales-agendas.plan.destroy', $agenda), [
                'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('content_items', ['id' => $agenda->id, 'title' => 'Agenda 08:00']);
    }

    public function test_processed_agenda_statuses_cannot_be_edited_or_deleted_before_submission(): void
    {
        [$sales, $project] = $this->salesContext();

        foreach (['done', 'cancelled', 'rescheduled'] as $status) {
            $agenda = $this->agendaAt($sales, $project, '2026-10-29', '09:00', '10:00');
            $agenda->update(['status' => $status]);

            $this->actingAs($sales)
                ->withHeaders(['Accept' => 'application/json'])
                ->patch(route('sales-agendas.plan.update', $agenda), [
                    'sales_activity_category' => 'Canvassing',
                    'title' => 'Tidak boleh berubah',
                    'start_time' => '09:00',
                    'end_time' => '10:00',
                    'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
                ])
                ->assertStatus(422);

            $this->actingAs($sales)
                ->withHeaders(['Accept' => 'application/json'])
                ->delete(route('sales-agendas.plan.destroy', $agenda), [
                    'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
                ])
                ->assertStatus(422);

            $this->assertDatabaseHas('content_items', [
                'id' => $agenda->id,
                'status' => $status,
                'title' => 'Agenda 09:00',
            ]);
        }
    }

    public function test_non_owner_cannot_edit_or_delete_another_sales_agenda(): void
    {
        [$sales, $project] = $this->salesContext();
        $otherSales = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'sales')->value('id'),
            'branch_id' => $project->branch_id,
            'password_changed_at' => now(),
        ]);
        $agenda = $this->agendaAt($sales, $project, '2026-10-29', '09:00', '10:00');

        $this->actingAs($otherSales)
            ->patch(route('sales-agendas.plan.update', $agenda), [
                'sales_activity_category' => 'Canvassing',
                'title' => 'Tidak boleh',
                'start_time' => '09:00',
                'end_time' => '10:00',
                'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
            ])
            ->assertForbidden();

        $this->actingAs($otherSales)
            ->delete(route('sales-agendas.plan.destroy', $agenda), [
                'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('content_items', ['id' => $agenda->id]);
    }

    public function test_stale_edit_and_delete_return_conflict_without_mutating_the_agenda(): void
    {
        [$sales, $project] = $this->salesContext();
        $agenda = $this->agendaAt($sales, $project, '2026-10-29', '09:00', '10:00');
        $staleToken = app(OptimisticLockService::class)->token($agenda);
        $agenda->forceFill(['title' => 'Sudah diubah pihak lain', 'updated_at' => now()->addSecond()])->save();

        $this->actingAs($sales)
            ->withHeaders(['Accept' => 'application/json'])
            ->patch(route('sales-agendas.plan.update', $agenda), [
                'sales_activity_category' => 'Canvassing',
                'title' => 'Perubahan stale',
                'start_time' => '09:00',
                'end_time' => '10:00',
                'expected_updated_at' => $staleToken,
            ])
            ->assertStatus(409);

        $this->actingAs($sales)
            ->withHeaders(['Accept' => 'application/json'])
            ->delete(route('sales-agendas.plan.destroy', $agenda), [
                'expected_updated_at' => $staleToken,
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('content_items', ['id' => $agenda->id, 'title' => 'Sudah diubah pihak lain']);
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
            ->assertRedirect(route('sales-agendas.index', ['work_date' => '2026-10-31']));

        $this->assertSame('rescheduled', $agenda->fresh()->status);
        $this->assertDatabaseHas('content_items', [
            'rescheduled_from_id' => $agenda->id,
            'scheduled_date' => '2026-11-01 00:00:00',
            'status' => 'planned',
        ]);
    }

    public function test_owner_can_complete_an_agenda_without_losing_the_selected_date(): void
    {
        [$sales, $project] = $this->salesContext();
        $agenda = $this->agenda($sales, $project, 60, '2026-10-31');

        $this->actingAs($sales)
            ->patch(route('sales-agendas.update', $agenda), [
                'activity_result' => 'Selesai ditindaklanjuti.',
                'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
            ])
            ->assertRedirect(route('sales-agendas.index', ['work_date' => '2026-10-31']));
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

    private function agenda(User $sales, LeadMaster $project, int $duration, string $scheduledDate = '2026-10-31'): ContentItem
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
            'scheduled_date' => $scheduledDate,
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

    private function agendaAt(User $sales, LeadMaster $project, string $date, string $start, string $end): ContentItem
    {
        $agenda = ContentItem::create([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'sales_project_id' => $project->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => 'Agenda '.$start,
            'sales_activity_category' => 'Follow-up',
            'scheduled_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'duration_minutes' => ((int) substr($end, 0, 2) * 60 + (int) substr($end, 3, 2)) - ((int) substr($start, 0, 2) * 60 + (int) substr($start, 3, 2)),
            'status' => 'planned',
            'owner_user_id' => $sales->id,
            'created_by' => $sales->id,
        ]);
        $agenda->assignees()->sync([$sales->id]);

        return $agenda;
    }
}
