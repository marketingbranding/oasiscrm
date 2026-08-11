<?php

namespace App\Services;

use App\Enums\SalesLeadStatus;
use App\Models\ContentItem;
use App\Models\SalesLead;
use App\Models\SalesLeadStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class CoordinatorSalesMonitoringService
{
    public function __construct(
        private readonly CoordinatorLeadTeamService $coordinatorTeam,
        private readonly OrganizationScopeService $organizationScope,
        private readonly WorkspaceAccessService $workspaceAccess,
    ) {}

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
            ->with(['owner:id,name', 'branch:id,name', 'salesProject:id,project_name'])
            ->orderByDesc('scheduled_date')->orderByDesc('id');
        $leads = $this->leadBase($salesIds, $scope)
            ->whereDate('lead_date', '>=', $period['from']->toDateString())
            ->whereDate('lead_date', '<=', $period['to']->toDateString())
            ->with(['branch:id,name', 'project:id,project_name', 'sales:id,name', 'leadSource:id,name'])
            ->latest('lead_date')->latest('id');

        return [
            'period' => $period,
            'filters' => [
                'period' => $period['key'],
                'date_from' => $period['from']->toDateString(),
                'date_to' => $period['to']->toDateString(),
                'sales_id' => $salesId,
            ],
            'salesUsers' => $filterSalesUsers,
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
            'exportData' => (clone $leads)->reorder('lead_date')->orderBy('id')->get(),
            'agendas' => $paginate
                ? $agendas->paginate(15, ['*'], 'agenda_page')->withQueryString()
                : $agendas->get(),
        ];
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
        return [
            'visible_user_ids' => $this->organizationScope->visibleUserIds($actor, 'sales_pocketbook', 'view'),
            'branch_ids' => array_values(array_intersect(
                $this->organizationScope->branchIds($actor, 'sales_pocketbook', 'view'),
                $this->workspaceAccess->accessibleBranchIds($actor),
            )),
            'project_ids' => array_values(array_intersect(
                $this->organizationScope->projectIds($actor, 'sales_pocketbook', 'view'),
                $this->workspaceAccess->accessibleProjectIds($actor),
            )),
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

        $sales = $this->coordinatorTeam->currentSalesQuery($actor)
            ->whereIn('users.id', $scope['visible_user_ids'])
            ->whereHas('assignedProjects', $assignment)
            ->with([
                'branch:id,name',
                'assignedProjects' => fn ($query) => $assignment($query)->orderByDesc('project_user.is_primary')->orderBy('lead_master.project_name'),
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
        $today = today()->toDateString();

        return SalesLead::query()
            ->whereIn('sales_user_id', $salesIds)
            ->whereIn('branch_id', $scope['branch_ids'])
            ->whereIn('project_id', $scope['project_ids'])
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('project_user')
                ->whereColumn('project_user.user_id', 'sales_leads.sales_user_id')
                ->whereColumn('project_user.project_id', 'sales_leads.project_id')
                ->where('project_user.is_active', true)
                ->where(fn ($query) => $query->whereNull('assignment_start_date')->orWhereDate('assignment_start_date', '<=', $today))
                ->where(fn ($query) => $query->whereNull('assignment_end_date')->orWhereDate('assignment_end_date', '>=', $today)));
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
        $today = today()->toDateString();

        return ContentItem::query()
            ->where('item_type', 'agenda')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->whereIn('owner_user_id', $salesIds)
            ->whereIn('branch_id', $scope['branch_ids'])
            ->whereIn('sales_project_id', $scope['project_ids'])
            ->whereDate('scheduled_date', '>=', $period['from']->toDateString())
            ->whereDate('scheduled_date', '<=', $period['to']->toDateString())
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('project_user')
                ->whereColumn('project_user.user_id', 'content_items.owner_user_id')
                ->whereColumn('project_user.project_id', 'content_items.sales_project_id')
                ->where('project_user.is_active', true)
                ->where(fn ($query) => $query->whereNull('assignment_start_date')->orWhereDate('assignment_start_date', '<=', $today))
                ->where(fn ($query) => $query->whereNull('assignment_end_date')->orWhereDate('assignment_end_date', '>=', $today)));
    }
}
