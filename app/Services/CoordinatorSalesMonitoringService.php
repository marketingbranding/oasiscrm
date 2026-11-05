<?php

namespace App\Services;

use App\Enums\SalesLeadStatus;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\SalesLeadStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class CoordinatorSalesMonitoringService
{
    public function __construct(
        private readonly CoordinatorLeadTeamService $coordinatorTeam,
        private readonly SalesTeamScopeService $salesTeamScope,
        private readonly OrganizationScopeService $organizationScope,
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly SalesAgendaReceiptService $receiptService,
    ) {}

    public function canViewAgenda(User $actor, ContentItem $agenda): bool
    {
        if (! $this->coordinatorTeam->isCoordinator($actor)
            || $agenda->item_type !== 'agenda'
            || $agenda->agenda_type !== ContentItem::SALES_AGENDA_TYPE
            || ! in_array((int) $agenda->branch_id, $this->scope($actor)['branch_ids'], true)
            || ! in_array((int) $agenda->sales_project_id, $this->scope($actor)['project_ids'], true)) {
            return false;
        }

        return $this->salesTeamScope->contains($actor, (int) $agenda->owner_user_id);
    }

    public function canManageAgenda(User $actor, ContentItem $agenda): bool
    {
        return $actor->hasPermission('sales_pocketbook.manage_team')
            && $this->canViewAgenda($actor, $agenda);
    }

    public function weeklyIncompleteAgendas(User $actor, ?CarbonImmutable $referenceDate = null)
    {
        abort_unless($this->coordinatorTeam->isCoordinator($actor), 403);

        $today = ($referenceDate ?? CarbonImmutable::today(config('app.timezone')))->startOfDay();
        $scope = $this->scope($actor);
        $salesUsers = $this->salesUsers($actor, $scope);
        $salesIds = $salesUsers->pluck('id')->map(fn ($id) => (int) $id)->all();

        $agendas = $this->agendaBase($salesIds, $scope, [
            'from' => $today->startOfWeek(),
            'to' => $today->subDay(),
        ])
            ->whereNotIn('status', ['cancelled', 'rescheduled'])
            ->where(function (Builder $query): void {
                $query->whereNull('activity_result')
                    ->orWhere('activity_result', '')
                    ->orWhereDoesntHave('evidence', fn (Builder $evidence) => $evidence->whereNull('purged_at'));
            })
            ->with(['owner:id,name', 'branch:id,name', 'salesProject:id,project_name', 'evidence'])
            ->orderBy('scheduled_date')
            ->orderBy('start_time')
            ->get();

        return $agendas->groupBy('owner_user_id')->map(function ($items) {
            $items->each(function (ContentItem $agenda): void {
                $agenda->setAttribute('missing_result', blank($agenda->activity_result));
                $agenda->setAttribute('missing_evidence', $agenda->evidence->whereNull('purged_at')->isEmpty());
            });

            return $items;
        })->sortKeys();
    }

    public function todayWorkspace(User $actor, ?CarbonImmutable $referenceDate = null, ?int $requestedSalesId = null): array
    {
        abort_unless($this->coordinatorTeam->isCoordinator($actor), 403);

        $date = ($referenceDate ?? CarbonImmutable::today(config('app.timezone')))->startOfDay();
        $scope = $this->scope($actor);
        $salesUsers = $this->salesUsers($actor, $scope);
        if ($requestedSalesId !== null) {
            abort_unless($salesUsers->contains('id', $requestedSalesId), 403);
            $salesUsers = $salesUsers->where('id', $requestedSalesId)->values();
        }
        $salesIds = $salesUsers->pluck('id')->map(fn ($id) => (int) $id)->all();
        $agendaItems = $this->agendaBase($salesIds, $scope, ['from' => $date, 'to' => $date])
            ->with(['evidence', 'rescheduledFrom:id,scheduled_date'])
            ->orderBy('start_time')
            ->get()
            ->groupBy('owner_user_id');
        $incompleteBySales = $this->weeklyIncompleteAgendas($actor, $date);
        $cards = $salesUsers->map(function (User $sales) use ($date, $incompleteBySales, $agendaItems): array {
            $summary = $this->receiptService->summarize($sales, $date);
            $backlogCount = $incompleteBySales->get($sales->id, collect())->count();
            $priority = ! $summary['submitted'] ? 0 : ($backlogCount > 0 ? 1 : ($summary['agenda_count'] > 0 && $summary['completed_count'] === $summary['agenda_count'] ? 3 : 2));

            return [
                'sales' => $sales,
                'summary' => $summary,
                'backlog_count' => $backlogCount,
                'priority' => $priority,
                'agenda_items' => $agendaItems->get($sales->id, collect()),
            ];
        })->sortBy(fn (array $card): array => [$card['priority'], $card['sales']->name])->values();

        return [
            'date' => $date,
            'cards' => $cards,
            'submitted_count' => $cards->where('summary.submitted', true)->count(),
            'sales_count' => $cards->count(),
            'completed_agenda_count' => $cards->sum(fn (array $card): int => $card['summary']['completed_count']),
            'agenda_count' => $cards->sum(fn (array $card): int => $card['summary']['agenda_count']),
            'incomplete_agendas' => $incompleteBySales,
            'incomplete_count' => $incompleteBySales->sum(fn ($items): int => $items->count()),
        ];
    }

    public function salesDetail(User $actor, int $salesId, ?CarbonImmutable $selectedDate = null): array
    {
        abort_unless($this->coordinatorTeam->isCoordinator($actor), 403);

        $scope = $this->scope($actor);
        $sales = $this->salesUsers($actor, $scope)->firstWhere('id', $salesId);
        abort_unless($sales, 403);

        $date = ($selectedDate ?? CarbonImmutable::today(config('app.timezone')))->startOfDay();
        $weekStart = $date->startOfWeek();
        $weekEnd = $date->endOfWeek();
        $agendas = $this->agendaBase([$salesId], $scope, ['from' => $weekStart, 'to' => $weekEnd])
            ->with(['evidence', 'branch:id,name', 'salesProject:id,project_name'])
            ->orderBy('scheduled_date')->orderBy('start_time')->get();
        $days = collect(range(0, 6))->map(function (int $offset) use ($weekStart, $agendas): array {
            $day = $weekStart->addDays($offset);
            $items = $agendas->filter(fn (ContentItem $agenda): bool => $agenda->scheduled_date?->isSameDay($day));
            $incomplete = $items->contains(fn (ContentItem $agenda): bool => ! in_array($agenda->status, ['cancelled', 'rescheduled'], true)
                && (blank($agenda->activity_result) || $agenda->evidence->whereNull('purged_at')->isEmpty()));

            return [
                'date' => $day,
                'items' => $items->values(),
                'state' => $items->isEmpty() ? ($day->isWeekend() ? 'weekend_empty' : 'weekday_empty') : ($incomplete ? 'incomplete' : 'complete'),
            ];
        });

        return [
            'sales' => $sales,
            'selected_date' => $date,
            'week' => $days,
            'agendas' => $agendas->filter(fn (ContentItem $agenda): bool => $agenda->scheduled_date?->isSameDay($date))->values(),
            'backlog' => $agendas->filter(fn (ContentItem $agenda): bool => $agenda->scheduled_date?->lt($date)
                && ! in_array($agenda->status, ['cancelled', 'rescheduled'], true)
                && (blank($agenda->activity_result) || $agenda->evidence->whereNull('purged_at')->isEmpty()))->values(),
        ];
    }

    public function resolve(User $actor, array $filters, bool $paginate = true): array
    {
        abort_unless($this->coordinatorTeam->isCoordinator($actor), 403);

        $period = $this->period($filters);
        $scope = $this->scope($actor);
        $filterSalesUsers = $this->salesUsers($actor, $scope);
        $salesId = filled($filters['sales_id'] ?? null) ? (int) $filters['sales_id'] : null;

        abort_if($salesId && ! $filterSalesUsers->contains('id', $salesId), 403);

        $salesUsers = $salesId
            ? $filterSalesUsers->where('id', $salesId)->values()
            : $filterSalesUsers;
        $salesIds = $salesUsers->pluck('id')->map(fn ($id) => (int) $id)->all();
        $leadNew = $this->leadBase($salesIds, $scope)
            ->whereDate('lead_date', '>=', $period['from']->toDateString())
            ->whereDate('lead_date', '<=', $period['to']->toDateString())
            ->selectRaw('sales_user_id, COUNT(*) AS total')->groupBy('sales_user_id')->pluck('total', 'sales_user_id');
        $statusCounts = $this->statusCounts($salesIds, $scope, $period);
        $salesRows = $salesUsers->map(function (User $sales) use ($leadNew, $statusCounts) {
            $counts = $statusCounts->get($sales->id, collect())->keyBy('status');

            return (object) [
                'id' => $sales->id,
                'name' => $sales->name,
                'branch_name' => $sales->branch?->name,
                'project_name' => $sales->resolved_project_name,
                'lead_new' => (int) ($leadNew[$sales->id] ?? 0),
                'face_to_face' => (int) ($counts[SalesLeadStatus::FaceToFace->value]?->total ?? 0),
                'site_visit' => (int) ($counts[SalesLeadStatus::SiteVisit->value]?->total ?? 0),
                'utj' => (int) ($counts[SalesLeadStatus::Utj->value]?->total ?? 0),
            ];
        });
        $agendas = $this->agendaBase($salesIds, $scope, $period)
            ->with(['owner:id,name', 'branch:id,name', 'salesProject:id,project_name', 'evidence'])
            ->orderByDesc('scheduled_date')->orderByDesc('id');
        $leads = $this->leadBase($salesIds, $scope)
            ->whereDate('lead_date', '>=', $period['from']->toDateString())
            ->whereDate('lead_date', '<=', $period['to']->toDateString())
            ->with(['branch:id,name', 'project:id,project_name', 'sales:id,name', 'leadSource:id,name'])
            ->latest('lead_date')->latest('id');
        $staleDays = max(1, min(14, (int) ($filters['stale_days'] ?? 2)));
        $actionableLeads = $this->leadBase($salesIds, $scope)
            ->whereIn('current_status', array_map(fn (SalesLeadStatus $status): string => $status->value, SalesLeadStatus::MANUAL))
            ->where('updated_at', '<=', now(config('app.timezone'))->subDays($staleDays))
            ->with(['branch:id,name', 'project:id,project_name', 'sales:id,name'])
            ->latest('updated_at')->get();
        $failedSyncLeads = $this->leadBase($salesIds, $scope)
            ->where('sync_status', 'sync_failed')
            ->with(['branch:id,name', 'project:id,project_name', 'sales:id,name'])
            ->latest('updated_at')->get();

        $result = [
            'period' => $period,
            'filters' => [
                'period' => $period['key'],
                'date_from' => $period['from']->toDateString(),
                'date_to' => $period['to']->toDateString(),
                'sales_id' => $salesId,
            ],
            'salesUsers' => $filterSalesUsers,
            'actionableLeads' => $actionableLeads,
            'failedSyncLeads' => $failedSyncLeads,
            'staleDays' => $staleDays,
            'selectedSalesUsers' => $salesUsers,
            'kpi' => [
                'lead_new' => $salesRows->sum('lead_new'),
                'face_to_face' => $salesRows->sum('face_to_face'),
                'site_visit' => $salesRows->sum('site_visit'),
                'utj' => $salesRows->sum('utj'),
            ],
            'salesRows' => $salesRows,
            'leads' => $paginate
                ? $leads->paginate(20, ['*'], 'lead_page')->withQueryString()
                : $leads->get(),
            'agendas' => $paginate
                ? $agendas->paginate(15, ['*'], 'agenda_page')->withQueryString()
                : $agendas->get(),
        ];
        if (! $paginate) {
            $result['exportData'] = (clone $leads)->reorder('lead_date')->orderBy('id')->get();
        }

        return $result;
    }

    private function period(array $filters): array
    {
        $timezone = config('app.timezone');
        $today = now($timezone)->toImmutable();
        $key = in_array($filters['period'] ?? 'week', ['today', 'week', 'month', 'custom'], true)
            ? ($filters['period'] ?? 'week')
            : 'week';
        [$from, $to] = match ($key) {
            'today' => [$today->startOfDay(), $today->endOfDay()],
            'month' => [$today->startOfMonth(), $today->endOfMonth()],
            'custom' => [
                CarbonImmutable::parse($filters['date_from'] ?? $today, $timezone)->startOfDay(),
                CarbonImmutable::parse($filters['date_to'] ?? $today, $timezone)->endOfDay(),
            ],
            default => [$today->startOfWeek(), $today->endOfWeek()],
        };
        abort_if($from->gt($to), 422);

        return compact('key', 'from', 'to');
    }

    private function scope(User $actor): array
    {
        $branchIds = array_values(array_intersect(
            $this->organizationScope->branchIds($actor, 'sales_pocketbook', 'view'),
            $this->workspaceAccess->accessibleBranchIds($actor),
        ));
        $salesIds = $this->salesTeamScope->for($actor)['sales']->pluck('id');
        $today = today()->toDateString();

        return [
            'branch_ids' => $branchIds,
            'project_ids' => LeadMaster::query()
                ->whereIn('branch_id', $branchIds)
                ->whereIn('id', $this->workspaceAccess->accessibleProjectIds($actor))
                ->where('is_active', true)
                ->whereHas('assignedUsers', fn ($query) => $query
                    ->whereIn('users.id', $salesIds)
                    ->where('project_user.is_active', true)
                    ->where(fn ($query) => $query->whereNull('project_user.assignment_start_date')->orWhereDate('project_user.assignment_start_date', '<=', $today))
                    ->where(fn ($query) => $query->whereNull('project_user.assignment_end_date')->orWhereDate('project_user.assignment_end_date', '>=', $today)))
                ->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ];
    }

    private function salesUsers(User $actor, array $scope)
    {
        $today = today()->toDateString();
        $assignment = fn ($query) => $query
            ->whereIn('lead_master.id', $scope['project_ids'])
            ->where('lead_master.is_active', true)
            ->where('project_user.is_active', true)
            ->where(fn ($query) => $query->whereNull('project_user.assignment_start_date')->orWhereDate('project_user.assignment_start_date', '<=', $today))
            ->where(fn ($query) => $query->whereNull('project_user.assignment_end_date')->orWhereDate('project_user.assignment_end_date', '>=', $today));
        $teamSalesIds = $this->salesTeamScope->displayedFor($actor, $scope['branch_ids'], $scope['project_ids'])['sales']->pluck('id');

        $sales = User::query()
            ->whereIn('users.id', $teamSalesIds)
            ->with([
                'branch:id,name',
                'assignedProjects' => fn ($query) => $assignment($query)->with('branch:id,name')->orderByDesc('project_user.is_primary')->orderBy('lead_master.project_name'),
            ])->orderBy('name')->get(['users.id', 'users.name', 'users.branch_id']);

        $sales->each(function (User $user) {
            $primary = $user->assignedProjects->where('pivot.is_primary', true);
            $project = $primary->count() === 1 ? $primary->first() : ($user->assignedProjects->count() === 1 ? $user->assignedProjects->first() : null);
            $user->setAttribute('resolved_project_name', $project?->project_name);
        });

        return $sales;
    }

    private function leadBase(array $salesIds, array $scope): Builder
    {
        return SalesLead::query()
            ->whereIn('sales_user_id', $salesIds)
            ->whereIn('branch_id', $scope['branch_ids'])
            ->whereIn('project_id', $scope['project_ids']);
    }

    private function statusCounts(array $salesIds, array $scope, array $period)
    {
        $firstEvents = SalesLeadStatusHistory::query()
            ->selectRaw('sales_lead_id, status, MIN(changed_at) AS first_changed_at')
            ->whereIn('status', [
                SalesLeadStatus::FaceToFace->value,
                SalesLeadStatus::SiteVisit->value,
                SalesLeadStatus::Utj->value,
            ])
            ->groupBy('sales_lead_id', 'status');

        return $this->leadBase($salesIds, $scope)
            ->joinSub($firstEvents, 'first_events', fn ($join) => $join->on('first_events.sales_lead_id', '=', 'sales_leads.id'))
            ->whereDate('first_events.first_changed_at', '>=', $period['from']->toDateString())
            ->whereDate('first_events.first_changed_at', '<=', $period['to']->toDateString())
            ->selectRaw('sales_leads.sales_user_id, first_events.status, COUNT(DISTINCT sales_leads.id) AS total')
            ->groupBy('sales_leads.sales_user_id', 'first_events.status')->get()->groupBy('sales_user_id');
    }

    private function agendaBase(array $salesIds, array $scope, array $period): Builder
    {
        return ContentItem::query()
            ->where('item_type', 'agenda')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->whereIn('owner_user_id', $salesIds)
            ->whereIn('branch_id', $scope['branch_ids'])
            ->where(function (Builder $query) use ($scope) {
                $query->whereIn('sales_project_id', $scope['project_ids'])
                    ->orWhereNull('sales_project_id');
            })
            ->whereDate('scheduled_date', '>=', $period['from']->toDateString())
            ->whereDate('scheduled_date', '<=', $period['to']->toDateString());
    }
}
