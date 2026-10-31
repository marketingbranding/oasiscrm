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

    /**
     * @return array{
     *     normal_minutes: int,
     *     target_minutes: int,
     *     remaining_minutes: int,
     *     completed_count: int,
     *     agenda_count: int,
     *     submitted: bool,
     *     extra_count: int,
     * }
     */
    public function summarize(User $owner, CarbonImmutable $date): array
    {
        $normalAgendas = $this->normalAgendaQuery($owner, $date)->get(['id', 'duration_minutes', 'status']);
        $extraAgendas = $this->agendaQuery($owner, $date)
            ->where('is_outside_schedule', true)
            ->whereNotIn('status', ['cancelled', 'rescheduled'])
            ->count();
        $normalMinutes = (int) $normalAgendas->sum(fn (ContentItem $agenda): int => (int) $agenda->duration_minutes);

        return [
            'normal_minutes' => $normalMinutes,
            'target_minutes' => self::TARGET_MINUTES,
            'remaining_minutes' => max(0, self::TARGET_MINUTES - $normalMinutes),
            'completed_count' => $normalAgendas->where('status', 'done')->count(),
            'agenda_count' => $normalAgendas->count(),
            'submitted' => $this->submissionQuery($owner, $date)->exists(),
            'extra_count' => $extraAgendas,
        ];
    }

    public function canAddNormal(User $owner, CarbonImmutable $date, int $durationMinutes): bool
    {
        if ($durationMinutes < 1 || $this->submissionQuery($owner, $date)->exists()) {
            return false;
        }

        $summary = $this->summarize($owner, $date);

        return $summary['normal_minutes'] + $durationMinutes <= self::TARGET_MINUTES;
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
            if ($summary['normal_minutes'] !== self::TARGET_MINUTES) {
                throw new RuntimeException('Agenda harus mencapai 420 menit sebelum dikirim ke Koordinator.');
            }

            return SalesAgendaDaySubmission::create([
                'owner_user_id' => $owner->id,
                'scheduled_date' => $date->toDateString(),
                'target_minutes' => self::TARGET_MINUTES,
                'normal_minutes_at_submission' => $summary['normal_minutes'],
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
            && ! $this->submissionQuery($actor, CarbonImmutable::parse($agenda->scheduled_date))->exists();
    }

    private function agendaQuery(User $owner, CarbonImmutable $date): Builder
    {
        return ContentItem::query()
            ->where('item_type', 'agenda')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->where('owner_user_id', $owner->id)
            ->whereDate('scheduled_date', $date->toDateString());
    }

    private function normalAgendaQuery(User $owner, CarbonImmutable $date): Builder
    {
        return $this->agendaQuery($owner, $date)
            ->where('is_outside_schedule', false)
            ->whereNotIn('status', ['cancelled', 'rescheduled']);
    }

    private function submissionQuery(User $owner, CarbonImmutable $date): Builder
    {
        return SalesAgendaDaySubmission::query()
            ->where('owner_user_id', $owner->id)
            ->whereDate('scheduled_date', $date->toDateString());
    }

    private function isSalesAgenda(ContentItem $agenda): bool
    {
        return $agenda->item_type === 'agenda'
            && $agenda->agenda_type === ContentItem::SALES_AGENDA_TYPE;
    }
}
