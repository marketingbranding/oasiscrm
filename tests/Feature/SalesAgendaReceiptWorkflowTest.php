<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\SalesAgendaDaySubmission;
use App\Models\SalesAgendaEvidence;
use App\Models\User;
use App\Services\SalesAgendaReceiptService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAgendaReceiptWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_excludes_cancelled_carried_forward_and_extra_agendas(): void
    {
        [$owner, $branch] = $this->salesContext();
        $date = CarbonImmutable::parse('2026-10-29');
        $this->agenda($owner, $branch, $date, 120);
        $this->agenda($owner, $branch, $date, 60, 'done');
        $this->agenda($owner, $branch, $date, 90, 'cancelled');
        $this->agenda($owner, $branch, $date, 30, 'rescheduled');
        $this->agenda($owner, $branch, $date, 180, 'done', true);

        $summary = app(SalesAgendaReceiptService::class)->summarize($owner, $date);

        $this->assertSame(180, $summary['normal_minutes']);
        $this->assertSame(300, $summary['remaining_minutes']);
        $this->assertSame(2, $summary['agenda_count']);
        $this->assertSame(1, $summary['completed_count']);
        $this->assertSame(1, $summary['extra_count']);
        $this->assertFalse($summary['submitted']);
    }

    public function test_capacity_allows_remaining_minutes_but_rejects_overflow(): void
    {
        [$owner, $branch] = $this->salesContext();
        $date = CarbonImmutable::parse('2026-10-29');
        $this->agendaAt($owner, $branch, $date, '08:00', '16:00');
        $service = app(SalesAgendaReceiptService::class);

        $this->assertTrue($service->canAddNormal($owner, $date, 60));
        $this->assertFalse($service->canAddNormal($owner, $date, 61));

        $this->agendaAt($owner, $branch, $date, '16:00', '17:00');

        $this->assertFalse($service->canAddNormal($owner, $date, 1));
    }

    public function test_exact_target_can_be_submitted_once(): void
    {
        [$owner, $branch] = $this->salesContext();
        $date = CarbonImmutable::parse('2026-10-29');
        $this->agendaAt($owner, $branch, $date, '08:00', '17:00');
        $service = app(SalesAgendaReceiptService::class);

        $submission = $service->submit($owner, $date);
        $sameSubmission = $service->submit($owner, $date);

        $this->assertSame($submission->id, $sameSubmission->id);
        $this->assertSame(1, SalesAgendaDaySubmission::query()->count());
        $this->assertSame(480, $submission->normal_minutes_at_submission);
        $this->assertFalse($service->canAddNormal($owner, $date, 30));
    }

    public function test_submission_requires_exact_target(): void
    {
        [$owner, $branch] = $this->salesContext();
        $date = CarbonImmutable::parse('2026-10-29');
        $this->agendaAt($owner, $branch, $date, '08:00', '16:59');

        $this->expectExceptionMessage('Agenda harus mencapai 480 menit sebelum dikirim ke Koordinator.');

        app(SalesAgendaReceiptService::class)->submit($owner, $date);
    }

    public function test_schedule_metadata_distinguishes_weekdays_friday_and_weekends(): void
    {
        [$owner] = $this->salesContext();
        $service = app(SalesAgendaReceiptService::class);

        $thursday = $service->schedule(CarbonImmutable::parse('2026-10-29'));
        $friday = $service->schedule(CarbonImmutable::parse('2026-10-30'));
        $sunday = $service->schedule(CarbonImmutable::parse('2026-11-01'));

        $this->assertFalse($thursday['is_weekend']);
        $this->assertSame('08:00', $thursday['start_time']);
        $this->assertSame('12:00', $thursday['break_start']);
        $this->assertSame('13:00', $thursday['break_end']);
        $this->assertTrue($thursday['requires_target']);

        $this->assertSame('07:30', $friday['start_time']);
        $this->assertSame('11:30', $friday['break_start']);
        $this->assertSame('13:00', $friday['break_end']);

        $this->assertTrue($sunday['is_weekend']);
        $this->assertNull($sunday['start_time']);
        $this->assertFalse($sunday['requires_target']);
        $this->assertSame(0, $service->summarize($owner, CarbonImmutable::parse('2026-11-01'))['target_minutes']);
    }

    public function test_weekend_summary_counts_all_agendas_without_treating_them_as_extra(): void
    {
        [$owner, $branch] = $this->salesContext();
        $date = CarbonImmutable::parse('2026-11-01');
        $this->agenda($owner, $branch, $date, 120);
        $this->agenda($owner, $branch, $date, 60, 'done', true);

        $summary = app(SalesAgendaReceiptService::class)->summarize($owner, $date);

        $this->assertSame(180, $summary['normal_minutes']);
        $this->assertSame(2, $summary['agenda_count']);
        $this->assertSame(1, $summary['completed_count']);
        $this->assertSame(0, $summary['extra_count']);
        $this->assertSame(0, $summary['remaining_minutes']);
    }

    public function test_submitted_summary_preserves_the_recorded_historical_target_and_minutes(): void
    {
        [$owner, $branch] = $this->salesContext();
        $date = CarbonImmutable::parse('2026-10-29');
        $this->agendaAt($owner, $branch, $date, '09:00', '16:00');
        SalesAgendaDaySubmission::create([
            'owner_user_id' => $owner->id,
            'scheduled_date' => $date,
            'target_minutes' => 420,
            'normal_minutes_at_submission' => 420,
            'submitted_at' => now(),
        ]);

        $summary = app(SalesAgendaReceiptService::class)->summarize($owner, $date);

        $this->assertSame(420, $summary['target_minutes']);
        $this->assertSame(420, $summary['normal_minutes']);
    }

    public function test_schedule_rejects_overlapping_slots(): void
    {
        [$owner, $branch] = $this->salesContext();
        $service = app(SalesAgendaReceiptService::class);
        $weekday = CarbonImmutable::parse('2026-10-29');
        $this->agendaAt($owner, $branch, $weekday, '09:00', '10:00');

        $this->expectExceptionMessage('Waktu agenda bertumpang tindih dengan agenda lain.');
        $service->assertTimeSlotAvailable($owner, $weekday, '09:30', '10:30');
    }

    public function test_schedule_allows_break_time_and_marks_break_agendas(): void
    {
        [$owner, $branch] = $this->salesContext();
        $service = app(SalesAgendaReceiptService::class);
        $date = CarbonImmutable::parse('2026-10-29');

        $service->assertTimeSlotAvailable($owner, $date, '12:00', '13:00');

        $agenda = $this->agendaAt($owner, $branch, $date, '12:00', '13:00');

        $this->assertTrue($service->isBreakAgenda($date, $agenda->start_time, $agenda->end_time));
        $this->assertSame(0, $service->effectiveMinutes($date, $agenda->start_time, $agenda->end_time));
    }

    public function test_effective_minutes_exclude_friday_break_for_crossing_agendas(): void
    {
        [$owner, $branch] = $this->salesContext();
        $date = CarbonImmutable::parse('2026-10-30');
        $agenda = $this->agendaAt($owner, $branch, $date, '11:30', '13:30');
        $agendaBeforeBreak = $this->agendaAt($owner, $branch, $date, '11:00', '13:30');

        $service = app(SalesAgendaReceiptService::class);

        $this->assertSame(30, $service->effectiveMinutes($date, $agenda->start_time, $agenda->end_time));
        $this->assertSame(60, $service->effectiveMinutes($date, $agendaBeforeBreak->start_time, $agendaBeforeBreak->end_time));
    }

    public function test_weekend_submission_requires_one_agenda_and_persists_weekend_marker(): void
    {
        [$owner, $branch] = $this->salesContext();
        $date = CarbonImmutable::parse('2026-11-01');
        $service = app(SalesAgendaReceiptService::class);

        $this->expectExceptionMessage('Agenda akhir pekan harus memiliki minimal satu agenda sebelum dikirim ke Koordinator.');
        $service->submit($owner, $date);

        $this->agendaAt($owner, $branch, $date, '10:00', '11:00');
        $submission = $service->submit($owner, $date);

        $this->assertTrue($submission->is_weekend);
        $this->assertSame(0, $submission->target_minutes);
    }

    public function test_deferral_preserves_original_and_does_not_copy_evidence(): void
    {
        [$owner, $branch] = $this->salesContext();
        $agenda = $this->agenda($owner, $branch, CarbonImmutable::parse('2026-10-31'), 60);
        $agenda->update(['activity_result' => 'Belum selesai', 'status' => 'planned']);
        SalesAgendaEvidence::create([
            'content_item_id' => $agenda->id,
            'uploaded_by_user_id' => $owner->id,
            'storage_path' => 'agenda/test.webp',
            'original_name' => 'test.webp',
            'mime_type' => 'image/webp',
            'width' => 10,
            'height' => 10,
            'size_bytes' => 3,
            'sha256' => hash('sha256', 'test'),
        ]);

        $copy = app(SalesAgendaReceiptService::class)->deferToNextDay($owner, $agenda);

        $this->assertSame('rescheduled', $agenda->fresh()->status);
        $this->assertSame('2026-10-31', $agenda->fresh()->scheduled_date->toDateString());
        $this->assertSame($agenda->id, $copy->rescheduled_from_id);
        $this->assertSame('2026-11-01', $copy->scheduled_date->toDateString());
        $this->assertSame('planned', $copy->status);
        $this->assertNull($copy->activity_result);
        $this->assertNull($copy->completed_at);
        $this->assertCount(1, $agenda->fresh()->evidence);
        $this->assertCount(0, $copy->evidence);
    }

    private function salesContext(): array
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SO', 'is_active' => true]);
        $owner = User::factory()->create(['branch_id' => $branch->id]);

        return [$owner, $branch];
    }

    private function agenda(User $owner, Branch $branch, CarbonImmutable $date, int $duration, string $status = 'planned', bool $outsideSchedule = false): ContentItem
    {
        $start = CarbonImmutable::createFromTime(9, 0);
        $end = $start->addMinutes($duration);

        return ContentItem::create([
            'branch_id' => $branch->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => 'Agenda '.$duration,
            'sales_activity_category' => 'Follow-up',
            'scheduled_date' => $date,
            'start_time' => $start->format('H:i'),
            'end_time' => $end->format('H:i'),
            'duration_minutes' => $duration,
            'status' => $status,
            'is_outside_schedule' => $outsideSchedule,
            'owner_user_id' => $owner->id,
            'created_by' => $owner->id,
        ]);
    }

    private function agendaAt(User $owner, Branch $branch, CarbonImmutable $date, string $start, string $end): ContentItem
    {
        return ContentItem::create([
            'branch_id' => $branch->id,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => 'Agenda '.$start,
            'sales_activity_category' => 'Follow-up',
            'scheduled_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'duration_minutes' => CarbonImmutable::parse($start)->diffInMinutes(CarbonImmutable::parse($end)),
            'status' => 'planned',
            'owner_user_id' => $owner->id,
            'created_by' => $owner->id,
        ]);
    }
}
