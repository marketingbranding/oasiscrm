<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\SalesAgendaDaySubmission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SalesAgendaReceiptService
{
    public const TARGET_MINUTES = SalesAgendaDaySubmission::TARGET_MINUTES;

    public function __construct(private readonly CrmAuthorizationService $authorization) {}

    /**
     * @return array{
     *     normal_minutes: int,
     *     target_minutes: int,
     *     remaining_minutes: int,
     *     completed_count: int,
     *     agenda_count: int,
     *     submitted: bool,
     *     extra_count: int,
     *     is_weekend: bool,
     *     requires_target: bool,
     *     schedule: array{is_weekend: bool, start_time: ?string, end_time: ?string, break_start: ?string, break_end: ?string, target_minutes: int, requires_target: bool},
     * }
     */
    public function summarize(User $owner, CarbonImmutable $date): array
    {
        $schedule = $this->schedule($date);
        $normalAgendas = ($schedule['is_weekend'] ? $this->agendaQuery($owner, $date) : $this->normalAgendaQuery($owner, $date))
            ->whereNotIn('status', ['cancelled', 'rescheduled'])
            ->get(['id', 'duration_minutes', 'status', 'start_time', 'end_time']);
        $extraAgendas = $this->agendaQuery($owner, $date)
            ->where('is_outside_schedule', true)
            ->whereNotIn('status', ['cancelled', 'rescheduled'])
            ->count();
        $normalMinutes = (int) $normalAgendas->sum(fn (ContentItem $agenda): int => $this->effectiveMinutes($date, $agenda->start_time, $agenda->end_time));
        $submission = $this->submissionQuery($owner, $date)->first();
        if ($submission) {
            $normalMinutes = (int) $submission->normal_minutes_at_submission;
        }
        $targetMinutes = $submission ? (int) $submission->target_minutes : $schedule['target_minutes'];

        return [
            'normal_minutes' => $normalMinutes,
            'target_minutes' => $targetMinutes,
            'remaining_minutes' => $schedule['requires_target'] ? max(0, $targetMinutes - $normalMinutes) : 0,
            'completed_count' => $normalAgendas->where('status', 'done')->count(),
            'agenda_count' => $normalAgendas->count(),
            'submitted' => $submission !== null,
            'extra_count' => $schedule['is_weekend'] ? 0 : $extraAgendas,
            'is_weekend' => $schedule['is_weekend'],
            'requires_target' => $schedule['requires_target'],
            'schedule' => $schedule,
        ];
    }

    /**
     * @return array{is_weekend: bool, start_time: ?string, end_time: ?string, break_start: ?string, break_end: ?string, target_minutes: int, requires_target: bool}
     */
    public function schedule(CarbonImmutable $date): array
    {
        if ($date->isWeekend()) {
            return [
                'is_weekend' => true,
                'start_time' => null,
                'end_time' => null,
                'break_start' => null,
                'break_end' => null,
                'target_minutes' => 0,
                'requires_target' => false,
            ];
        }

        $isFriday = $date->dayOfWeekIso === 5;

        return [
            'is_weekend' => false,
            'start_time' => $isFriday ? '07:30' : '08:00',
            'end_time' => '17:00',
            'break_start' => $isFriday ? '11:30' : '12:00',
            'break_end' => '13:00',
            'target_minutes' => self::TARGET_MINUTES,
            'requires_target' => true,
        ];
    }

    public function assertTimeSlotAvailable(User $owner, CarbonImmutable $date, string $startTime, string $endTime, ?int $ignoreAgendaId = null): void
    {
        $schedule = $this->schedule($date);
        $startMinute = $this->timeToMinutes($startTime);
        $endMinute = $this->timeToMinutes($endTime);

        if (! $schedule['is_weekend']) {
            $scheduleStart = $this->timeToMinutes($schedule['start_time']);
            $scheduleEnd = $this->timeToMinutes($schedule['end_time']);

            if ($startMinute < $scheduleStart || $endMinute > $scheduleEnd) {
                throw new RuntimeException('Agenda harus berada di dalam jam kerja yang tersedia.');
            }

        }

        $query = $this->agendaQuery($owner, $date)
            ->where('is_outside_schedule', false)
            ->whereNotIn('status', ['cancelled', 'rescheduled'])
            ->whereNotNull('start_time')
            ->whereNotNull('end_time');

        if ($ignoreAgendaId !== null) {
            $query->where('id', '<>', $ignoreAgendaId);
        }

        $overlaps = $query->get(['start_time', 'end_time'])->contains(function (ContentItem $agenda) use ($startMinute, $endMinute): bool {
            return $startMinute < $this->timeToMinutes(substr((string) $agenda->end_time, 0, 5))
                && $endMinute > $this->timeToMinutes(substr((string) $agenda->start_time, 0, 5));
        });

        if ($overlaps) {
            throw new RuntimeException('Waktu agenda bertumpang tindih dengan agenda lain.');
        }
    }

    public function canAddNormal(User $owner, CarbonImmutable $date, int $durationMinutes, ?int $effectiveMinutes = null): bool
    {
        if ($durationMinutes < 1 || $this->submissionQuery($owner, $date)->exists()) {
            return false;
        }

        $summary = $this->summarize($owner, $date);
        $effectiveMinutes ??= $durationMinutes;

        return ! $summary['requires_target'] || $summary['target_minutes'] >= $summary['normal_minutes'] + $effectiveMinutes;
    }

    public function updateDraft(User $actor, ContentItem $agenda, array $data): ContentItem
    {
        $this->assertDraftEditable($actor, $agenda);
        $date = CarbonImmutable::parse($agenda->scheduled_date);

        try {
            $this->assertTimeSlotAvailable($actor, $date, $data['start_time'], $data['end_time'], $agenda->id);
        } catch (RuntimeException $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        $duration = $this->durationMinutes($data['start_time'], $data['end_time']);
        $effectiveMinutes = $this->effectiveMinutes($date, $data['start_time'], $data['end_time']);
        if (! $this->canUpdateNormal($actor, $date, $agenda->id, $duration, $effectiveMinutes)) {
            throw new RuntimeException('Durasi agenda melebihi sisa waktu kerja '.self::TARGET_MINUTES.' menit.');
        }

        $agenda->update([
            'sales_activity_category' => $data['sales_activity_category'],
            'title' => $data['title'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'duration_minutes' => $duration,
            'location' => $data['location'] ?? null,
            'updated_by' => $actor->id,
        ]);

        return $agenda->fresh();
    }

    public function deleteDraft(User $actor, ContentItem $agenda): void
    {
        $this->assertDraftEditable($actor, $agenda);
        $agenda->delete();
    }

    public function canUpdateNormal(User $owner, CarbonImmutable $date, int $ignoreAgendaId, int $durationMinutes, int $effectiveMinutes): bool
    {
        if ($durationMinutes < 1 || $this->submissionQuery($owner, $date)->exists()) {
            return false;
        }

        $schedule = $this->schedule($date);
        if (! $schedule['requires_target']) {
            return true;
        }

        $normalMinutes = $this->normalAgendaMinutes($owner, $date, $ignoreAgendaId);

        return $schedule['target_minutes'] >= $normalMinutes + $effectiveMinutes;
    }

    public function submission(User $owner, CarbonImmutable $date): ?SalesAgendaDaySubmission
    {
        return $this->submissionQuery($owner, $date)->first();
    }

    public function submit(User $owner, CarbonImmutable $date): SalesAgendaDaySubmission
    {
        $existing = $this->submissionQuery($owner, $date)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($owner, $date): SalesAgendaDaySubmission {
            $existing = $this->submissionQuery($owner, $date)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $summary = $this->summarize($owner, $date);
            if (! $summary['requires_target'] && $summary['agenda_count'] < 1) {
                throw new RuntimeException('Agenda akhir pekan harus memiliki minimal satu agenda sebelum dikirim ke Koordinator.');
            }

            if ($summary['requires_target'] && $summary['normal_minutes'] !== self::TARGET_MINUTES) {
                throw new RuntimeException('Agenda harus mencapai 480 menit sebelum dikirim ke Koordinator.');
            }

            return SalesAgendaDaySubmission::create([
                'owner_user_id' => $owner->id,
                'scheduled_date' => $date->toDateString(),
                'target_minutes' => $summary['target_minutes'],
                'normal_minutes_at_submission' => $summary['normal_minutes'],
                'is_weekend' => $summary['is_weekend'],
                'submitted_at' => now(),
            ]);
        });
    }

    public function deferToNextDay(User $actor, ContentItem $agenda): ContentItem
    {
        return DB::transaction(fn (): ContentItem => $this->deferToNextDayLocked($actor, $agenda));
    }

    public function deferToNextDayLocked(User $actor, ContentItem $agenda): ContentItem
    {
        if ((int) $agenda->owner_user_id !== (int) $actor->id) {
            throw new AuthorizationException('Anda tidak dapat menunda Agenda Sales ini.');
        }

        if (! $this->isSalesAgenda($agenda)) {
            throw new RuntimeException('Item yang dipilih bukan Agenda Sales.');
        }

        if (in_array($agenda->status, ['cancelled', 'rescheduled'], true)) {
            throw new RuntimeException('Agenda ini tidak dapat ditunda.');
        }

        if ($agenda->rescheduledItems()->exists()) {
            throw new RuntimeException('Agenda ini sudah memiliki Agenda lanjutan.');
        }

        $current = ContentItem::query()->lockForUpdate()->findOrFail($agenda->id);
        if ($current->rescheduledItems()->exists()) {
            throw new RuntimeException('Agenda ini sudah memiliki Agenda lanjutan.');
        }

        $copy = $current->replicate([
            'scheduled_date',
            'status',
            'completed_at',
            'activity_result',
            'daily_plan_id',
            'sort_order',
            'rescheduled_from_id',
            'is_outside_schedule',
            'updated_by',
        ]);
        $copy->fill([
            'scheduled_date' => CarbonImmutable::parse($current->scheduled_date)->addDay()->toDateString(),
            'status' => 'planned',
            'completed_at' => null,
            'activity_result' => null,
            'daily_plan_id' => null,
            'sort_order' => null,
            'rescheduled_from_id' => $current->id,
            'is_outside_schedule' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $copy->save();
        $copy->assignees()->sync([$copy->owner_user_id]);

        $current->update(['status' => 'rescheduled', 'updated_by' => $actor->id]);

        return $copy;
    }

    public function isScheduleEditable(User $actor, ContentItem $agenda): bool
    {
        return (int) $agenda->owner_user_id === (int) $actor->id
            && $agenda->status === 'planned'
            && ! $this->submissionQuery($actor, CarbonImmutable::parse($agenda->scheduled_date))->exists();
    }

    private function assertDraftEditable(User $actor, ContentItem $agenda): void
    {
        if (! $this->isSalesAgenda($agenda)) {
            throw new RuntimeException('Item yang dipilih bukan Agenda Sales.');
        }

        if ((int) $agenda->owner_user_id !== (int) $actor->id) {
            throw new AuthorizationException('Anda tidak dapat mengubah Agenda Sales ini.');
        }

        if ($agenda->status !== 'planned') {
            throw new RuntimeException('Agenda yang sudah diproses tidak dapat diubah dari struk draft.');
        }

        if ($this->submissionQuery($actor, CarbonImmutable::parse($agenda->scheduled_date))->exists()) {
            throw new RuntimeException('Struk agenda sudah dikirim ke Koordinator. Agenda tidak dapat diubah.');
        }
    }

    private function agendaQuery(User $owner, CarbonImmutable $date): Builder
    {
        $query = ContentItem::query()
            ->where('item_type', 'agenda')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->where('owner_user_id', $owner->id)
            ->whereDate('scheduled_date', $date->toDateString());

        if ($owner->isSales()) {
            $query
                ->whereIn('branch_id', $this->authorization->visibleBranchIds($owner, 'sales_pocketbook', 'view'))
                ->where(function (Builder $scoped) use ($owner): void {
                    $scoped
                        ->whereNull('sales_project_id')
                        ->orWhereIn('sales_project_id', $this->authorization->visibleProjectIds($owner, 'sales_pocketbook', 'view'));
                });
        }

        return $query;
    }

    private function normalAgendaQuery(User $owner, CarbonImmutable $date): Builder
    {
        return $this->agendaQuery($owner, $date)
            ->where('is_outside_schedule', false)
            ->whereNotIn('status', ['cancelled', 'rescheduled']);
    }

    private function normalAgendaMinutes(User $owner, CarbonImmutable $date, ?int $ignoreAgendaId = null): int
    {
        return (int) $this->normalAgendaQuery($owner, $date)
            ->when($ignoreAgendaId !== null, fn (Builder $query) => $query->where('id', '<>', $ignoreAgendaId))
            ->get(['start_time', 'end_time'])
            ->sum(fn (ContentItem $agenda): int => $this->effectiveMinutes($date, $agenda->start_time, $agenda->end_time));
    }

    private function submissionQuery(User $owner, CarbonImmutable $date): Builder
    {
        return SalesAgendaDaySubmission::query()
            ->where('owner_user_id', $owner->id)
            ->whereDate('scheduled_date', $date->toDateString());
    }

    public function effectiveMinutes(CarbonImmutable $date, ?string $startTime, ?string $endTime): int
    {
        if ($startTime === null || $endTime === null) {
            return 0;
        }

        $startMinute = $this->timeToMinutes($startTime);
        $endMinute = $this->timeToMinutes($endTime);
        $duration = max(0, $endMinute - $startMinute);

        if ($duration === 0 || $date->isWeekend()) {
            return $duration;
        }

        $schedule = $this->schedule($date);
        $breakStart = $this->timeToMinutes($schedule['break_start']);
        $breakEnd = $this->timeToMinutes($schedule['break_end']);
        $breakMinutes = max(0, min($endMinute, $breakEnd) - max($startMinute, $breakStart));

        return max(0, $duration - $breakMinutes);
    }

    public function isBreakAgenda(CarbonImmutable $date, ?string $startTime, ?string $endTime): bool
    {
        if ($date->isWeekend() || $startTime === null || $endTime === null) {
            return false;
        }

        $startMinute = $this->timeToMinutes($startTime);
        $endMinute = $this->timeToMinutes($endTime);
        $schedule = $this->schedule($date);

        return $startMinute < $this->timeToMinutes($schedule['break_end'])
            && $endMinute > $this->timeToMinutes($schedule['break_start']);
    }

    private function isSalesAgenda(ContentItem $agenda): bool
    {
        return $agenda->item_type === 'agenda'
            && $agenda->agenda_type === ContentItem::SALES_AGENDA_TYPE;
    }

    private function timeToMinutes(?string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', substr((string) $time, 0, 5)));

        return ($hours * 60) + $minutes;
    }

    private function durationMinutes(string $startTime, string $endTime): int
    {
        return CarbonImmutable::createFromFormat('H:i', $startTime)
            ->diffInMinutes(CarbonImmutable::createFromFormat('H:i', $endTime));
    }
}
