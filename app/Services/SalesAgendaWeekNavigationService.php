<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class SalesAgendaWeekNavigationService
{
    /** @var array<int, string> */
    private const INACTIVE_STATUSES = ['cancelled', 'rescheduled'];

    /** @var array<int, string> */
    private const COMPLETE_STATUSES = ['done', 'cancelled', 'rescheduled'];

    public function __construct(private readonly CrmAuthorizationService $authorization) {}

    /**
     * @return array{
     *     start: CarbonImmutable,
     *     end: CarbonImmutable,
     *     previous_date: CarbonImmutable,
     *     next_date: CarbonImmutable,
     *     days: array<int, array{date: CarbonImmutable, day_label: string, date_label: string, has_plan: bool, has_incomplete_agenda: bool, incomplete_count: int}>
     * }
     */
    public function for(User $owner, CarbonImmutable $selectedDate): array
    {
        $start = $selectedDate->startOfWeek(CarbonImmutable::MONDAY);
        $end = $start->addDays(6);
        $agendasByDate = $this->agendaQuery($owner, $start, $end)
            ->get(['scheduled_date', 'status'])
            ->groupBy(fn (ContentItem $agenda): string => CarbonImmutable::parse($agenda->scheduled_date)->toDateString());
        $days = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $date = $start->addDays($offset);
            $agendas = $agendasByDate->get($date->toDateString(), collect());
            $activeAgendas = $agendas->reject(fn (ContentItem $agenda): bool => in_array($agenda->status, self::INACTIVE_STATUSES, true));
            $incompleteAgendas = $agendas->reject(fn (ContentItem $agenda): bool => in_array($agenda->status, self::COMPLETE_STATUSES, true));

            $days[] = [
                'date' => $date,
                'day_label' => $date->locale('id')->translatedFormat('D'),
                'date_label' => $date->format('d'),
                'has_plan' => $activeAgendas->isNotEmpty(),
                'has_incomplete_agenda' => $incompleteAgendas->isNotEmpty(),
                'incomplete_count' => $incompleteAgendas->count(),
            ];
        }

        return [
            'start' => $start,
            'end' => $end,
            'previous_date' => $selectedDate->subWeek(),
            'next_date' => $selectedDate->addWeek(),
            'days' => $days,
        ];
    }

    private function agendaQuery(User $owner, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        $visibleBranchIds = $this->authorization->visibleBranchIds($owner, 'sales_pocketbook', 'view');
        $visibleProjectIds = $this->authorization->visibleProjectIds($owner, 'sales_pocketbook', 'view');

        return ContentItem::query()
            ->where('item_type', 'agenda')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->where('owner_user_id', $owner->id)
            ->whereIn('branch_id', $visibleBranchIds)
            ->where(function (Builder $query) use ($visibleProjectIds): void {
                $query
                    ->whereNull('sales_project_id')
                    ->orWhereIn('sales_project_id', $visibleProjectIds);
            })
            ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()]);
    }
}
