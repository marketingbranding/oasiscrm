<?php

namespace App\Services;

use App\Models\SalesCoordinatorSales;
use App\Models\SalesDailyPlan;
use App\Models\SalesDailyPlanItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SalesDailyPlanService
{
    public const TOTAL_WORK_MINUTES = 420;

    public function __construct(private readonly CollaborationNotificationService $notifications) {}

    public function saveDraft(User $sales, CarbonImmutable $workDate, array $activities): SalesDailyPlan
    {
        $this->assertWorkDate($workDate);
        $normalized = $this->normalizeActivities($activities);
        $this->assertTotalWithinLimit($normalized);

        return DB::transaction(function () use ($sales, $workDate, $normalized): SalesDailyPlan {
            $plan = SalesDailyPlan::query()
                ->where('sales_user_id', $sales->id)
                ->whereDate('work_date', $workDate->toDateString())
                ->first() ?? new SalesDailyPlan([
                    'sales_user_id' => $sales->id,
                    'work_date' => $workDate->toDateString(),
                ]);
            if ($plan->exists && $plan->status === 'submitted') {
                throw new AccessDeniedHttpException('Daily Plan yang sudah disubmit tidak dapat diubah oleh Sales.');
            }

            $plan->status = 'draft';
            $plan->save();
            $this->replaceItems($plan, $normalized, $workDate);

            return $plan->fresh('items');
        });
    }

    public function submit(User $sales, CarbonImmutable $workDate): SalesDailyPlan
    {
        $this->assertWorkDate($workDate);

        return DB::transaction(function () use ($sales, $workDate): SalesDailyPlan {
            $plan = SalesDailyPlan::query()
                ->where('sales_user_id', $sales->id)
                ->whereDate('work_date', $workDate->toDateString())
                ->first();
            if (! $plan) {
                throw ValidationException::withMessages(['daily_plan' => 'Daily Plan belum dibuat.']);
            }
            if ($plan->status === 'submitted') {
                return $plan->fresh('items');
            }
            if ($plan->totalDurationMinutes() !== self::TOTAL_WORK_MINUTES) {
                throw ValidationException::withMessages([
                    'duration_minutes' => 'Daily Plan hanya dapat disubmit jika total durasi tepat 420 menit.',
                ]);
            }

            $plan->forceFill([
                'status' => 'submitted',
                'submitted_at' => now(),
                'submitted_by' => $sales->id,
                'lock_version' => $plan->lock_version + 1,
            ])->save();

            return $plan->fresh('items');
        });
    }

    public function revise(User $coordinator, SalesDailyPlan $plan, array $activities): SalesDailyPlan
    {
        $this->assertCoordinatorAccess($coordinator, $plan);
        $normalized = $this->normalizeActivities($activities);
        $this->assertTotalExactly420($normalized);
        $workDate = CarbonImmutable::parse($plan->work_date->toDateString(), config('app.timezone'));

        return DB::transaction(function () use ($coordinator, $plan, $normalized, $workDate): SalesDailyPlan {
            $plan = SalesDailyPlan::query()->with('items')->lockForUpdate()->findOrFail($plan->id);
            if ($plan->status !== 'submitted') {
                throw ValidationException::withMessages(['daily_plan' => 'Hanya Daily Plan yang sudah disubmit yang dapat direvisi.']);
            }
            $before = $this->snapshot($plan);
            $this->replaceItems($plan, $normalized, $workDate, true);
            $plan->forceFill([
                'revised_at' => now(),
                'revised_by' => $coordinator->id,
                'lock_version' => $plan->lock_version + 1,
            ])->save();
            $plan->revisions()->create([
                'actor_user_id' => $coordinator->id,
                'action' => 'revised',
                'before_snapshot' => $before,
                'after_snapshot' => $this->snapshot($plan->fresh('items')),
            ]);
            $this->notifications->create(
                $plan->sales,
                'sales_daily_plan_revised',
                'Daily Plan direvisi Koordinator',
                $coordinator->name.' merevisi planning Anda. Silakan periksa kembali urutan dan detail aktivitas.',
                route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $plan->work_date->toDateString()]),
                $plan,
            );

            return $plan->fresh(['items', 'revisions']);
        });
    }

    public function completeItem(User $sales, SalesDailyPlanItem $item, string $activityResult): SalesDailyPlanItem
    {
        $item->loadMissing('plan');
        if ($item->plan->sales_user_id !== $sales->id || $item->plan->status !== 'submitted') {
            throw new AccessDeniedHttpException('Aktivitas ini bukan milik Sales yang sedang masuk.');
        }

        $item->forceFill([
            'activity_result' => $activityResult,
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => $sales->id,
        ])->save();

        return $item->fresh();
    }

    public function itemState(SalesDailyPlanItem $item, CarbonImmutable $now): string
    {
        if ($item->status === 'completed') {
            return 'completed';
        }

        $item->loadMissing('plan');
        $start = CarbonImmutable::parse(
            $item->plan->work_date->toDateString().' '.$item->start_time,
            $now->getTimezone(),
        );
        $end = CarbonImmutable::parse(
            $item->plan->work_date->toDateString().' '.$item->end_time,
            $now->getTimezone(),
        );

        return match (true) {
            $now->lt($start) => 'upcoming',
            $now->lt($end) => 'ongoing',
            default => 'overdue',
        };
    }

    private function assertWorkDate(CarbonImmutable $workDate): void
    {
        if (! $workDate->isWeekday()) {
            throw ValidationException::withMessages(['work_date' => 'Daily Plan hanya dapat dibuat pada hari kerja Senin sampai Jumat.']);
        }
    }

    private function normalizeActivities(array $activities): array
    {
        if ($activities === []) {
            throw ValidationException::withMessages(['activities' => 'Minimal satu aktivitas harus diisi.']);
        }

        return collect($activities)->values()->map(function (array $activity, int $position): array {
            $duration = filter_var($activity['duration_minutes'] ?? null, FILTER_VALIDATE_INT);
            if (! is_int($duration) || $duration < 1 || $duration > self::TOTAL_WORK_MINUTES) {
                throw ValidationException::withMessages([
                    "activities.{$position}.duration_minutes" => 'Durasi aktivitas harus antara 1 sampai 420 menit.',
                ]);
            }
            if (blank($activity['activity'] ?? null)) {
                throw ValidationException::withMessages([
                    "activities.{$position}.activity" => 'Aktivitas wajib diisi.',
                ]);
            }

            return [
                'id' => isset($activity['id']) ? (int) $activity['id'] : null,
                'activity' => trim((string) $activity['activity']),
                'detail' => filled($activity['detail'] ?? null) ? trim((string) $activity['detail']) : null,
                'duration_minutes' => $duration,
            ];
        })->all();
    }

    private function assertTotalWithinLimit(array $activities): void
    {
        if (collect($activities)->sum('duration_minutes') > self::TOTAL_WORK_MINUTES) {
            throw ValidationException::withMessages(['duration_minutes' => 'Total durasi Daily Plan tidak boleh lebih dari 420 menit.']);
        }
    }

    private function assertTotalExactly420(array $activities): void
    {
        if (collect($activities)->sum('duration_minutes') !== self::TOTAL_WORK_MINUTES) {
            throw ValidationException::withMessages(['duration_minutes' => 'Total durasi revisi harus tepat 420 menit.']);
        }
    }

    private function replaceItems(SalesDailyPlan $plan, array $activities, CarbonImmutable $workDate, bool $preserveExisting = false): void
    {
        $schedule = $this->allocateSchedule($activities, $workDate);
        $existing = $preserveExisting ? $plan->items()->get()->keyBy('id') : collect();
        $requestedIds = collect($activities)->pluck('id')->filter()->map(fn (int $id) => $id)->all();

        if ($preserveExisting) {
            $existing->except($requestedIds)->each->delete();
            $existing->each(function (SalesDailyPlanItem $item): void {
                $item->update(['position' => $item->position + 10000]);
            });
        } else {
            $plan->items()->delete();
        }

        foreach ($schedule as $position => $itemData) {
            $activity = $activities[$position];
            $item = $activity['id'] ? $existing->get($activity['id']) : null;
            if ($activity['id'] && ! $item) {
                throw ValidationException::withMessages(["activities.{$position}.id" => 'Aktivitas tidak termasuk dalam Daily Plan ini.']);
            }
            $attributes = [
                'position' => $position,
                'activity' => $activity['activity'],
                'detail' => $activity['detail'],
                'duration_minutes' => $activity['duration_minutes'],
                'start_time' => $itemData['start_time'],
                'end_time' => $itemData['end_time'],
            ];
            if ($item) {
                $item->update($attributes);
            } else {
                $plan->items()->create($attributes + ['status' => 'planned']);
            }
        }
    }

    private function allocateSchedule(array $activities, CarbonImmutable $workDate): array
    {
        $date = $workDate->toDateString();
        $cursor = CarbonImmutable::parse("{$date} 09:00", config('app.timezone'));
        $lunchStart = CarbonImmutable::parse("{$date} 12:00", config('app.timezone'));
        $afternoonStart = CarbonImmutable::parse("{$date} 13:00", config('app.timezone'));
        $workEnd = CarbonImmutable::parse("{$date} 17:00", config('app.timezone'));
        $schedule = [];

        foreach ($activities as $activity) {
            if ($cursor->gte($lunchStart) && $cursor->lt($afternoonStart)) {
                $cursor = $afternoonStart;
            }
            $start = $cursor;
            $remaining = $activity['duration_minutes'];
            while ($remaining > 0) {
                if ($cursor->gte($lunchStart) && $cursor->lt($afternoonStart)) {
                    $cursor = $afternoonStart;
                }
                if ($cursor->gte($workEnd)) {
                    throw ValidationException::withMessages(['duration_minutes' => 'Aktivitas melampaui jam kerja efektif.']);
                }
                $blockEnd = $cursor->lt($lunchStart) ? $lunchStart : $workEnd;
                $available = intdiv($blockEnd->getTimestamp() - $cursor->getTimestamp(), 60);
                $consumed = min($remaining, $available);
                $cursor = $cursor->addMinutes($consumed);
                $remaining -= $consumed;
            }
            $schedule[] = [
                'start_time' => $start->format('H:i:s'),
                'end_time' => $cursor->format('H:i:s'),
            ];
        }

        return $schedule;
    }

    private function assertCoordinatorAccess(User $coordinator, SalesDailyPlan $plan): void
    {
        $allowed = SalesCoordinatorSales::query()
            ->current()
            ->where('coordinator_user_id', $coordinator->id)
            ->where('sales_user_id', $plan->sales_user_id)
            ->exists();
        if (! $allowed) {
            throw new AccessDeniedHttpException('Koordinator tidak memiliki akses ke Daily Plan Sales ini.');
        }
    }

    private function snapshot(SalesDailyPlan $plan): array
    {
        return $plan->items()->get()->map(fn (SalesDailyPlanItem $item): array => [
            'id' => $item->id,
            'position' => $item->position,
            'activity' => $item->activity,
            'detail' => $item->detail,
            'duration_minutes' => $item->duration_minutes,
            'start_time' => $item->start_time,
            'end_time' => $item->end_time,
            'status' => $item->status,
        ])->all();
    }
}
