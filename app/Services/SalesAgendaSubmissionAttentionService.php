<?php

namespace App\Services;

use App\Models\SalesAgendaDaySubmission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SalesAgendaSubmissionAttentionService
{
    public function __construct(
        private readonly SalesWorkCalendarService $calendar,
        private readonly AppSettingResolver $settings,
    ) {}

    public function missingFor(User $viewer, CarbonImmutable $now): Collection
    {
        return $this->missingForMany(collect([$viewer]), $now);
    }

    /**
     * @param  Collection<int, User>  $viewers
     * @return Collection<int, User>
     */
    public function missingForMany(Collection $viewers, CarbonImmutable $now): Collection
    {
        $sales = $viewers->filter(fn (User $viewer): bool => $viewer->isSales());
        $workingBranchIds = $sales->pluck('branch_id')->filter()->unique()->filter(
            fn (int|string $branchId): bool => $this->isPastSubmissionCutoff($now, (int) $branchId),
        );

        if ($workingBranchIds->isEmpty()) {
            return collect();
        }

        $submittedOwnerIds = SalesAgendaDaySubmission::query()
            ->whereIn('owner_user_id', $sales->pluck('id'))
            ->whereDate('scheduled_date', $now->toDateString())
            ->pluck('owner_user_id');

        return $sales
            ->filter(fn (User $viewer): bool => $workingBranchIds->contains((int) $viewer->branch_id))
            ->reject(fn (User $viewer): bool => $submittedOwnerIds->contains($viewer->id))
            ->values();
    }

    public function summaryFor(User $viewer, CarbonImmutable $now): array
    {
        return [
            'missing' => $this->missingFor($viewer, $now),
            'requires_submission' => $this->requiresSubmission($viewer, $now),
            'cutoff_time' => $this->settings->string('sales_monitoring.daily_plan_cutoff_time'),
        ];
    }

    public function isPastSubmissionCutoff(CarbonImmutable $now, int $branchId): bool
    {
        if ($this->calendar->isFreeDay($now, $branchId)) {
            return false;
        }

        $cutoff = CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $now->toDateString().' '.$this->settings->string('sales_monitoring.daily_plan_cutoff_time'),
            $now->getTimezone(),
        );

        return $now->gte($cutoff);
    }

    private function requiresSubmission(User $viewer, CarbonImmutable $now): bool
    {
        return $viewer->isSales()
            && $this->isPastSubmissionCutoff($now, (int) $viewer->branch_id)
            && ! SalesAgendaDaySubmission::query()
                ->where('owner_user_id', $viewer->id)
                ->whereDate('scheduled_date', $now->toDateString())
                ->exists();
    }
}
