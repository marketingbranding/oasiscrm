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
        $date = CarbonImmutable::parse('2026-10-31');
        $this->agenda($owner, $branch, $date, 120);
        $this->agenda($owner, $branch, $date, 60, 'done');
        $this->agenda($owner, $branch, $date, 90, 'cancelled');
        $this->agenda($owner, $branch, $date, 30, 'rescheduled');
        $this->agenda($owner, $branch, $date, 180, 'done', true);

        $summary = app(SalesAgendaReceiptService::class)->summarize($owner, $date);

        $this->assertSame(180, $summary['normal_minutes']);
        $this->assertSame(240, $summary['remaining_minutes']);
        $this->assertSame(2, $summary['agenda_count']);
        $this->assertSame(1, $summary['completed_count']);
        $this->assertSame(1, $summary['extra_count']);
        $this->assertFalse($summary['submitted']);
    }

    public function test_capacity_allows_remaining_minutes_but_rejects_overflow(): void
    {
        [$owner, $branch] = $this->salesContext();
        $date = CarbonImmutable::parse('2026-10-31');
        $this->agenda($owner, $branch, $date, 419);
        $service = app(SalesAgendaReceiptService::class);

        $this->assertTrue($service->canAddNormal($owner, $date, 1));
        $this->assertFalse($service->canAddNormal($owner, $date, 2));

        $this->agenda($owner, $branch, $date, 1);

        $this->assertFalse($service->canAddNormal($owner, $date, 1));
    }

    public function test_exact_target_can_be_submitted_once(): void
    {
        [$owner, $branch] = $this->salesContext();
        $date = CarbonImmutable::parse('2026-10-31');
        $this->agenda($owner, $branch, $date, 420);
        $service = app(SalesAgendaReceiptService::class);

        $submission = $service->submit($owner, $date);
        $sameSubmission = $service->submit($owner, $date);

        $this->assertSame($submission->id, $sameSubmission->id);
        $this->assertSame(1, SalesAgendaDaySubmission::query()->count());
        $this->assertSame(420, $submission->normal_minutes_at_submission);
        $this->assertFalse($service->canAddNormal($owner, $date, 30));
    }

    public function test_submission_requires_exact_target(): void
    {
        [$owner, $branch] = $this->salesContext();
        $date = CarbonImmutable::parse('2026-10-31');
        $this->agenda($owner, $branch, $date, 419);

        $this->expectExceptionMessage('Agenda harus mencapai 420 menit sebelum dikirim ke Koordinator.');

        app(SalesAgendaReceiptService::class)->submit($owner, $date);
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
}
