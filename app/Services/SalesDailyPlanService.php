<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesDailyPlan;
use App\Models\SalesDailyPlanItem;
use App\Models\SalesDailyPlanItemEvidence;
use App\Models\SalesDailyPlanItemReschedule;
use App\Models\User;
use App\Policies\SalesDailyPlanPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SalesDailyPlanService
{
    public const TOTAL_WORK_MINUTES = SalesDailyPlan::REQUIRED_WORK_MINUTES;

    public function __construct(
        private readonly SalesDailyPlanScheduleService $schedule,
        private readonly CollaborationNotificationService $notifications,
        private readonly SyncLockService $locks,
    ) {}

    public function create(User $actor, string $date): SalesDailyPlan
    {
        $planDate = CarbonImmutable::parse($date);
        if (! $actor->isSales() || ! $actor->branch_id) {
            throw new InvalidArgumentException('Daily Plan hanya dapat dibuat oleh Sales aktif yang memiliki cabang.');
        }

        return SalesDailyPlan::query()->firstOrCreate(
            ['sales_user_id' => $actor->id, 'plan_date' => $planDate->toDateString()],
            [
                'branch_id' => $actor->branch_id,
                'work_date' => $planDate->toDateString(),
                'is_weekend' => $planDate->isWeekend(),
                'status' => 'draft',
                'last_modified_by' => $actor->id,
                'lock_version' => 0,
            ],
        );
    }

    public function addAgenda(SalesDailyPlan $plan, User $actor, array $data): ContentItem
    {
        $this->assertPlanMutationAllowed($plan, $actor);
        if (filled($data['sales_project_id'] ?? null)) {
            $project = LeadMaster::query()->find((int) $data['sales_project_id']);
            if (! $project || ! $project->is_active || (int) $project->branch_id !== (int) $plan->branch_id) {
                throw new InvalidArgumentException('Proyek Agenda harus aktif dan berada di cabang Daily Plan.');
            }
        }
        $nextOrder = ((int) $plan->agendas()->max('sort_order')) + 1;
        $agenda = ContentItem::create([
            'branch_id' => $plan->branch_id,
            'sales_project_id' => $data['sales_project_id'] ?? null,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'daily_plan_id' => $plan->id,
            'visibility' => 'personal',
            'title' => $data['title'],
            'task_detail' => $data['task_detail'] ?? null,
            'location' => $data['location'] ?? null,
            'scheduled_date' => ($plan->plan_date ?? $plan->work_date)->toDateString(),
            'start_time' => $data['start_time'] ?? null,
            'status' => 'planned',
            'duration_minutes' => (int) $data['duration_minutes'],
            'sort_order' => $nextOrder,
            'sales_activity_category' => $data['sales_activity_category'],
            'owner_user_id' => $plan->sales_user_id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->recalculate($plan, $actor);

        return $agenda->fresh();
    }

    public function updateAgenda(SalesDailyPlan $plan, ContentItem $agenda, User $actor, array $data): SalesDailyPlan
    {
        $this->assertAgendaBelongs($plan, $agenda);
        $this->assertPlanMutationAllowed($plan, $actor);
        $agenda->update([
            'title' => $data['title'],
            'task_detail' => $data['task_detail'] ?? null,
            'location' => $data['location'] ?? null,
            'start_time' => $data['start_time'] ?? $agenda->start_time,
            'duration_minutes' => (int) $data['duration_minutes'],
            'sales_activity_category' => $data['sales_activity_category'],
            'updated_by' => $actor->id,
        ]);

        return $this->recalculate($plan, $actor);
    }

    public function deleteAgenda(SalesDailyPlan $plan, ContentItem $agenda, User $actor): SalesDailyPlan
    {
        $this->assertAgendaBelongs($plan, $agenda);
        $this->assertPlanMutationAllowed($plan, $actor);
        $agenda->delete();

        return $this->recalculate($plan, $actor);
    }

    /** @param array<int, int|string> $agendaIds */
    public function reorder(SalesDailyPlan $plan, array $agendaIds, User $actor): SalesDailyPlan
    {
        $this->assertPlanMutationAllowed($plan, $actor);
        $ids = array_values(array_unique(array_map('intval', $agendaIds)));
        $agendas = $plan->agendas()->whereIn('id', $ids)->get()->keyBy('id');
        if ($agendas->count() !== count($ids) || $agendas->count() !== $plan->agendas()->count()) {
            throw new InvalidArgumentException('Daftar Agenda reorder tidak sesuai dengan Daily Plan.');
        }
        foreach ($ids as $index => $id) {
            $agendas->get($id)->update(['sort_order' => $index + 1, 'updated_by' => $actor->id]);
        }

        $plan->logActivity('agenda_reordered', ['agenda_ids' => $ids]);

        return $this->recalculate($plan, $actor);
    }

    public function submit(SalesDailyPlan|User $first, User|CarbonImmutable $second): SalesDailyPlan
    {
        if ($first instanceof SalesDailyPlan) {
            return $this->submitAgendaPlan($first, $second);
        }

        return $this->submitActivityPlan($first, $second);
    }

    public function recalculate(SalesDailyPlan $plan, User $actor): SalesDailyPlan
    {
        $wasSubmitted = $plan->status === 'submitted';
        $schedule = $this->schedule->calculate($plan);
        if ($plan->status === 'submitted') {
            $this->schedule->assertSubmitAllowed($schedule);
        } else {
            $this->schedule->assertDraftAllowed($schedule);
        }

        $byId = collect($schedule['items'])->keyBy('id');
        foreach ($plan->agendas()->get()->values() as $index => $agenda) {
            $item = $byId->get($agenda->id);
            $agenda->update([
                'start_time' => $item['start_time'],
                'end_time' => $item['end_time'],
                'sort_order' => $index + 1,
                'updated_by' => $actor->id,
            ]);
        }
        $plan->update([
            'last_modified_by' => $actor->id,
            'lock_version' => ((int) $plan->lock_version) + 1,
        ]);
        $plan->logActivity('agenda_schedule_recalculated', [
            'total_minutes' => $schedule['total_minutes'],
            'remaining_minutes' => $schedule['remaining_minutes'],
        ]);
        if ($wasSubmitted && $actor->hasPrimaryRole('sales_coordinator')) {
            $plan->loadMissing('sales');
            $this->notifications->create(
                $plan->sales,
                'sales_daily_plan_corrected',
                'Daily Plan diperbarui Koordinator',
                $actor->name.' memperbarui planning Daily Plan '.($plan->plan_date ?? $plan->work_date)->format('d/m/Y').'.',
                route('sales-daily-plans.index', ['date' => ($plan->plan_date ?? $plan->work_date)->toDateString()]),
                $plan,
            );
        }

        return $plan->fresh(['agendas']);
    }

    public function saveDraft(User $sales, CarbonImmutable $workDate, array $activities): SalesDailyPlan
    {
        $normalized = $this->normalizeActivities($activities);
        $this->assertTotalWithinLimit($normalized);

        return $this->locks->runOrThrow($this->planLockKey($sales, $workDate), function () use ($sales, $workDate, $normalized): SalesDailyPlan {
            return DB::transaction(function () use ($sales, $workDate, $normalized): SalesDailyPlan {
                $plan = SalesDailyPlan::query()
                    ->where('sales_user_id', $sales->id)
                    ->whereDate('work_date', $workDate->toDateString())
                    ->lockForUpdate()
                    ->first() ?? new SalesDailyPlan([
                        'sales_user_id' => $sales->id,
                        'branch_id' => $sales->branch_id,
                        'work_date' => $workDate->toDateString(),
                    ]);
                $this->assertBranchSnapshot($plan);
                if ($plan->exists && $plan->status === 'submitted') {
                    throw new AccessDeniedHttpException('Daily Plan yang sudah disubmit tidak dapat diubah oleh Sales.');
                }

                $preserveExisting = $plan->exists;
                $plan->status = 'draft';
                $plan->save();
                $this->replaceItems($plan, $normalized, $workDate, $preserveExisting);

                return $plan->fresh('items');
            });
        }, 120);
    }

    /**
     * @return array{source: SalesDailyPlanItem, target: SalesDailyPlanItem}
     */
    public function rescheduleItem(User $sales, SalesDailyPlanItem $item, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Alasan penjadwalan ulang wajib diisi dan maksimal 1000 karakter.']);
        }

        return DB::transaction(function () use ($sales, $item, $reason): array {
            $source = SalesDailyPlanItem::query()
                ->with('plan')
                ->lockForUpdate()
                ->findOrFail($item->id);
            $this->assertItemOwner($sales, $source);
            if ($source->plan->status !== 'submitted' || in_array($source->status, ['completed', 'rescheduled'], true)) {
                throw new AccessDeniedHttpException('Aktivitas ini tidak dapat dijadwalkan ulang.');
            }

            $sourceDate = CarbonImmutable::parse($source->plan->work_date->toDateString(), config('app.timezone'));
            [$targetPlan, $targetDate] = $this->findRescheduleTargetPlan($sales, $sourceDate, $source->duration_minutes);
            $targetItems = $targetPlan->items()->orderBy('position')->get();
            $targetSchedule = $this->allocateSchedule($targetItems->map(fn (SalesDailyPlanItem $target): array => [
                'duration_minutes' => $target->duration_minutes,
            ])->push([
                'duration_minutes' => $source->duration_minutes,
            ])->all(), $targetDate);
            $target = $targetPlan->items()->create([
                'position' => ((int) $targetItems->max('position')) + 1,
                'sales_activity_category' => $source->sales_activity_category,
                'activity' => $source->activity,
                'location' => $source->location,
                'detail' => $source->detail,
                'duration_minutes' => $source->duration_minutes,
                'start_time' => $targetSchedule[array_key_last($targetSchedule)]['start_time'],
                'end_time' => $targetSchedule[array_key_last($targetSchedule)]['end_time'],
                'status' => 'planned',
            ]);

            $source->forceFill(['status' => 'rescheduled'])->save();
            SalesDailyPlanItemReschedule::create([
                'source_item_id' => $source->id,
                'target_item_id' => $target->id,
                'actor_user_id' => $sales->id,
                'source_work_date' => $source->plan->work_date->toDateString(),
                'target_work_date' => $targetDate->toDateString(),
                'reason' => $reason,
            ]);
            ActivityLog::create([
                'causer_id' => $sales->id,
                'subject_type' => SalesDailyPlanItem::class,
                'subject_id' => $source->id,
                'event' => 'sales_daily_plan_item_rescheduled',
                'description' => 'Aktivitas Daily Plan dijadwalkan ulang.',
                'properties' => [
                    'source_item_id' => $source->id,
                    'target_item_id' => $target->id,
                    'source_work_date' => $source->plan->work_date->toDateString(),
                    'target_work_date' => $targetDate->toDateString(),
                    'reason' => $reason,
                ],
            ]);
            ActivityLog::create([
                'causer_id' => $sales->id,
                'subject_type' => SalesDailyPlanItem::class,
                'subject_id' => $target->id,
                'event' => 'sales_daily_plan_item_rescheduled_copy_created',
                'description' => 'Salinan aktivitas Daily Plan dibuat pada hari kerja berikutnya.',
                'properties' => ['source_item_id' => $source->id, 'target_item_id' => $target->id],
            ]);

            return [
                'source' => $source->fresh(['plan', 'rescheduleTo']),
                'target' => $target->fresh(['plan', 'rescheduleFrom']),
            ];
        });
    }

    public function revise(User $coordinator, SalesDailyPlan $plan, array $activities): SalesDailyPlan
    {
        $this->assertCoordinatorAccess($coordinator, $plan);
        $normalized = $this->normalizeActivities($activities);
        $this->assertTotalExactly420($normalized);
        $workDate = CarbonImmutable::parse(($plan->work_date ?? $plan->plan_date)->toDateString(), config('app.timezone'));

        return DB::transaction(function () use ($coordinator, $plan, $normalized, $workDate): SalesDailyPlan {
            $plan = SalesDailyPlan::query()->with('items')->lockForUpdate()->findOrFail($plan->id);
            if ($plan->status !== 'submitted') {
                throw ValidationException::withMessages(['daily_plan' => 'Hanya Daily Plan yang sudah disubmit yang dapat direvisi.']);
            }
            $before = $this->snapshot($plan);
            $this->replaceItems($plan, $normalized, $workDate, true);
            if ($plan->items()->sum('duration_minutes') !== self::TOTAL_WORK_MINUTES) {
                throw ValidationException::withMessages([
                    'duration_minutes' => 'Total durasi revisi harus tetap tepat 420 menit setelah disimpan.',
                ]);
            }
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
                route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => ($plan->work_date ?? $plan->plan_date)->toDateString()]),
                $plan,
            );

            return $plan->fresh(['items', 'revisions']);
        });
    }

    public function completeItem(User $sales, SalesDailyPlanItem $item, string $activityResult): SalesDailyPlanItem
    {
        return DB::transaction(function () use ($sales, $item, $activityResult): SalesDailyPlanItem {
            $item = SalesDailyPlanItem::query()->with('plan')->lockForUpdate()->findOrFail($item->id);
            $this->assertItemWritable($sales, $item);
            $item->forceFill([
                'activity_result' => $activityResult,
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => $sales->id,
            ])->save();
            ActivityLog::create([
                'causer_id' => $sales->id,
                'subject_type' => SalesDailyPlanItem::class,
                'subject_id' => $item->id,
                'event' => 'sales_daily_plan_item_completed',
                'description' => 'Aktivitas Daily Plan diselesaikan dengan hasil.',
                'properties' => ['daily_plan_item_id' => $item->id],
            ]);

            return $item->fresh();
        });
    }

    public function assertItemOwner(User $sales, SalesDailyPlanItem $item): void
    {
        $item->loadMissing('plan');
        if (! $item->plan || $item->plan->sales_user_id !== $sales->id) {
            throw new AccessDeniedHttpException('Aktivitas ini bukan milik Sales yang sedang masuk.');
        }
    }

    public function assertItemWritable(User $sales, SalesDailyPlanItem $item): void
    {
        $this->assertItemOwner($sales, $item);
        if ($item->plan->status !== 'submitted' || in_array($item->status, ['completed', 'rescheduled'], true)) {
            throw new AccessDeniedHttpException('Aktivitas ini tidak dapat diubah lagi.');
        }
    }

    public function itemState(SalesDailyPlanItem $item, CarbonImmutable $now): string
    {
        if ($item->status === 'completed') {
            return 'completed';
        }
        if ($item->status === 'rescheduled') {
            return 'rescheduled';
        }

        $item->loadMissing('plan');
        $date = ($item->plan->work_date ?? $item->plan->plan_date)->toDateString();
        $start = CarbonImmutable::parse($date.' '.$item->start_time, $now->getTimezone());
        $end = CarbonImmutable::parse($date.' '.$item->end_time, $now->getTimezone());

        return match (true) {
            $now->lt($start) => 'upcoming',
            $now->lt($end) => 'ongoing',
            default => 'overdue',
        };
    }

    private function submitAgendaPlan(SalesDailyPlan $plan, User $actor): SalesDailyPlan
    {
        if (! app(SalesDailyPlanPolicy::class)->submit($actor, $plan)) {
            throw new InvalidArgumentException('Anda tidak dapat mengirim Daily Plan ini.');
        }
        $schedule = $this->schedule->calculate($plan);
        $this->schedule->assertSubmitAllowed($schedule);
        $plan->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by' => $actor->id,
            'last_modified_by' => $actor->id,
            'lock_version' => ((int) $plan->lock_version) + 1,
        ]);
        $plan->logActivity('daily_plan_submitted', ['total_minutes' => $schedule['total_minutes']]);
        $plan->loadMissing('sales.currentSalesCoordinators');
        foreach ($plan->sales->currentSalesCoordinators as $coordinator) {
            if (! $coordinator->isAccountActive() || ! $coordinator->is_active || ! $coordinator->hasPrimaryRole('sales_coordinator')) {
                continue;
            }
            $this->notifications->create(
                $coordinator,
                'sales_daily_plan_submitted',
                'Daily Plan dikirim',
                $plan->sales->name.' mengirim Daily Plan untuk '.($plan->plan_date ?? $plan->work_date)->format('d/m/Y').'.',
                route('sales-daily-plans.index', ['date' => ($plan->plan_date ?? $plan->work_date)->toDateString()]),
                $plan,
            );
        }

        return $plan->fresh(['agendas']);
    }

    private function submitActivityPlan(User $sales, CarbonImmutable $workDate): SalesDailyPlan
    {
        return $this->locks->runOrThrow($this->planLockKey($sales, $workDate), function () use ($sales, $workDate): SalesDailyPlan {
            return DB::transaction(function () use ($sales, $workDate): SalesDailyPlan {
                $plan = SalesDailyPlan::query()
                    ->where('sales_user_id', $sales->id)
                    ->whereDate('work_date', $workDate->toDateString())
                    ->lockForUpdate()
                    ->first();
                if (! $plan) {
                    throw ValidationException::withMessages(['daily_plan' => 'Daily Plan belum dibuat.']);
                }
                $this->assertBranchSnapshot($plan);
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
        }, 120);
    }

    private function assertPlanMutationAllowed(SalesDailyPlan $plan, User $actor): void
    {
        abort_unless(app(SalesDailyPlanPolicy::class)->update($actor, $plan), 403);
    }

    private function assertAgendaBelongs(SalesDailyPlan $plan, ContentItem $agenda): void
    {
        if ((int) $agenda->daily_plan_id !== (int) $plan->id
            || $agenda->item_type !== 'agenda'
            || $agenda->agenda_type !== ContentItem::SALES_AGENDA_TYPE) {
            throw (new ModelNotFoundException)->setModel(ContentItem::class, [$agenda->id]);
        }
    }

    private function planLockKey(User $sales, CarbonImmutable $workDate): string
    {
        return 'sales-daily-plan:'.$sales->id.':'.$workDate->toDateString();
    }

    private function normalizeActivities(array $activities): array
    {
        if ($activities === []) {
            throw ValidationException::withMessages(['activities' => 'Minimal satu aktivitas harus diisi.']);
        }

        $seenIds = [];

        return collect($activities)->values()->map(function (array $activity, int $position) use (&$seenIds): array {
            $id = isset($activity['id']) ? (int) $activity['id'] : null;
            if ($id !== null && isset($seenIds[$id])) {
                throw ValidationException::withMessages([
                    "activities.{$position}.id" => 'Aktivitas yang sama tidak boleh dikirim lebih dari satu kali.',
                ]);
            }
            if ($id !== null) {
                $seenIds[$id] = true;
            }
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
                'id' => $id,
                'sales_activity_category' => filled($activity['sales_activity_category'] ?? null)
                    ? trim((string) $activity['sales_activity_category'])
                    : null,
                'activity' => trim((string) $activity['activity']),
                'location' => filled($activity['location'] ?? null) ? trim((string) $activity['location']) : null,
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

    private function assertBranchSnapshot(SalesDailyPlan $plan): void
    {
        if ($plan->branch_id === null) {
            throw ValidationException::withMessages(['branch_id' => 'Daily Plan belum memiliki snapshot cabang historis dan harus dipulihkan secara eksplisit.']);
        }
    }

    private function assertTotalExactly420(array $activities): void
    {
        if (collect($activities)->sum('duration_minutes') !== self::TOTAL_WORK_MINUTES) {
            throw ValidationException::withMessages(['duration_minutes' => 'Total durasi revisi harus tepat 420 menit.']);
        }
    }

    /**
     * @return array{0: SalesDailyPlan, 1: CarbonImmutable}
     */
    private function findRescheduleTargetPlan(User $sales, CarbonImmutable $sourceDate, int $duration): array
    {
        $targetDate = $sourceDate->addDay();

        while (true) {
            if (! $targetDate->isWeekday()) {
                $targetDate = $targetDate->addDay();

                continue;
            }

            $plan = SalesDailyPlan::query()
                ->where('sales_user_id', $sales->id)
                ->whereDate('work_date', $targetDate->toDateString())
                ->lockForUpdate()
                ->first();

            if (! $plan) {
                $plan = SalesDailyPlan::query()->firstOrCreate(
                    [
                        'sales_user_id' => $sales->id,
                        'work_date' => $targetDate->toDateString(),
                    ],
                    ['branch_id' => $sales->branch_id, 'status' => 'draft'],
                );
                $plan = SalesDailyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            }
            if ($plan->branch_id === null) {
                throw ValidationException::withMessages(['branch_id' => 'Daily Plan lama belum memiliki snapshot cabang historis dan tidak dapat dipindahkan otomatis.']);
            }

            if ($plan->status === 'draft' && $plan->totalDurationMinutes() + $duration <= self::TOTAL_WORK_MINUTES) {
                return [$plan, $targetDate];
            }

            $targetDate = $targetDate->addDay();
        }
    }

    private function replaceItems(SalesDailyPlan $plan, array $activities, CarbonImmutable $workDate, bool $preserveExisting = false): void
    {
        $schedule = $this->allocateSchedule($activities, $workDate);
        $existing = $preserveExisting ? $plan->items()->get()->keyBy('id') : collect();
        $requestedIds = collect($activities)->pluck('id')->filter()->map(fn (int $id) => $id)->all();

        if ($preserveExisting) {
            $existingIds = collect($existing->keys());
            $protectedIds = SalesDailyPlanItemReschedule::query()
                ->where(function ($query) use ($existing): void {
                    $query->whereIn('source_item_id', $existing->keys()->all())
                        ->orWhereIn('target_item_id', $existing->keys()->all());
                })
                ->get(['source_item_id', 'target_item_id'])
                ->flatMap(fn (SalesDailyPlanItemReschedule $reschedule) => [$reschedule->source_item_id, $reschedule->target_item_id])
                ->filter()
                ->filter(fn (int $id): bool => $existingIds->contains($id))
                ->merge(SalesDailyPlanItemEvidence::query()->whereIn('sales_daily_plan_item_id', $existingIds->all())->pluck('sales_daily_plan_item_id'))
                ->unique()
                ->all();
            $removedProtectedIds = collect($protectedIds)->diff($requestedIds);
            if ($removedProtectedIds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'activities' => 'Aktivitas yang memiliki riwayat penjadwalan ulang atau bukti foto tidak dapat dihapus dari Daily Plan.',
                ]);
            }
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
                'sales_activity_category' => $activity['sales_activity_category'],
                'activity' => $activity['activity'],
                'location' => $activity['location'],
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
            'sales_activity_category' => $item->sales_activity_category,
            'activity' => $item->activity,
            'location' => $item->location,
            'detail' => $item->detail,
            'duration_minutes' => $item->duration_minutes,
            'start_time' => $item->start_time,
            'end_time' => $item->end_time,
            'status' => $item->status,
        ])->all();
    }
}
