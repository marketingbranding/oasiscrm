<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SupervisorSalesMonitoringService
{
    public function __construct(
        private readonly OrganizationScopeService $organizationScope,
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly SalesTeamScopeService $salesTeamScope,
    ) {}

    public function isSupervisor(User $user): bool
    {
        return $user->hasPrimaryRole('supervisor');
    }

    public function resolve(User $actor, array $filters, bool $paginate = true): array
    {
        $period = $this->period($filters);
        $scope = $this->scope($actor);
        $team = $this->team($actor, $scope);
        $coordinatorId = filled($filters['coordinator_id'] ?? null) ? (int) $filters['coordinator_id'] : null;
        $salesId = filled($filters['sales_id'] ?? null) ? (int) $filters['sales_id'] : null;

        abort_if($coordinatorId && ! $team['coordinators']->contains('id', $coordinatorId), 403);
        abort_if($salesId && ! isset($team['coordinator_names_by_sales_id'][$salesId]), 403);
        abort_if($coordinatorId && $salesId && ! isset($team['sales_ids_by_coordinator'][$coordinatorId][$salesId]), 403);

        $sales = $team['sales'];
        if ($coordinatorId) {
            $sales = $sales->whereIn('id', array_keys($team['sales_ids_by_coordinator'][$coordinatorId] ?? []))->values();
        }
        if ($salesId) {
            $sales = $sales->where('id', $salesId)->values();
        }
        $salesIds = $sales->pluck('id')->map(fn ($id) => (int) $id)->all();

        $agendaAggregate = $this->agendaBase($salesIds, $period, $scope)
            ->selectRaw("owner_user_id, COUNT(*) AS total, SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done, SUM(CASE WHEN COALESCE(activity_result, '') = '' AND status NOT IN ('cancelled', 'rescheduled') THEN 1 ELSE 0 END) AS missing")
            ->groupBy('owner_user_id')->get()->keyBy('owner_user_id');
        $leadAggregate = $this->leadBase($salesIds, $period, $scope)
            ->selectRaw("sales_user_id, COUNT(*) AS total, SUM(CASE WHEN sync_status = 'pending_create' THEN 1 ELSE 0 END) AS pending_create, SUM(CASE WHEN sync_status = 'pending_update' THEN 1 ELSE 0 END) AS pending_update, SUM(CASE WHEN sync_status = 'sync_failed' THEN 1 ELSE 0 END) AS sync_failed, MAX(created_at) AS latest_created_at, MAX(lead_date) AS latest_lead_date")
            ->groupBy('sales_user_id')->get()->keyBy('sales_user_id');

        $salesRows = $sales->map(function (User $user) use ($agendaAggregate, $leadAggregate, $team) {
            $agenda = $agendaAggregate->get($user->id);
            $lead = $leadAggregate->get($user->id);

            return (object) [
                'id' => $user->id,
                'name' => $user->name,
                'coordinator_names' => $team['coordinator_names_by_sales_id'][$user->id] ?? [],
                'branch_name' => $user->branch?->name,
                'project_name' => $user->resolved_project_name,
                'agenda_count' => (int) ($agenda?->total ?? 0),
                'agenda_done' => (int) ($agenda?->done ?? 0),
                'missing_result' => (int) ($agenda?->missing ?? 0),
                'lead_count' => (int) ($lead?->total ?? 0),
                'pending_create' => (int) ($lead?->pending_create ?? 0),
                'pending_update' => (int) ($lead?->pending_update ?? 0),
                'sync_failed' => (int) ($lead?->sync_failed ?? 0),
                'latest_agenda' => null,
                'latest_lead' => $lead?->latest_created_at ?? $lead?->latest_lead_date,
            ];
        });

        $latestAgendas = $this->agendaBase($salesIds, $period, $scope)->select('owner_user_id', DB::raw('MAX(scheduled_date) AS latest'))->groupBy('owner_user_id')->pluck('latest', 'owner_user_id');
        $salesRows->each(fn ($row) => $row->latest_agenda = $latestAgendas[$row->id] ?? null);

        $coordinators = $team['coordinators'];
        if ($coordinatorId) {
            $coordinators = $coordinators->where('id', $coordinatorId)->values();
        }
        $coordinatorRows = $coordinators->map(function (User $coordinator) use ($salesRows, $team) {
            $rows = $salesRows->whereIn('id', array_keys($team['sales_ids_by_coordinator'][$coordinator->id] ?? []));

            return (object) [
                'id' => $coordinator->id,
                'name' => $coordinator->name,
                'sales_count' => $rows->count(),
                'lead_count' => $rows->sum('lead_count'),
                'pending_create' => $rows->sum('pending_create'),
                'pending_update' => $rows->sum('pending_update'),
                'sync_failed' => $rows->sum('sync_failed'),
                'latest_lead' => $rows->max(fn ($row) => $row->latest_lead),
            ];
        });

        $agendas = ($salesId || ! $paginate)
            ? $this->agendaBase($salesIds, $period, $scope)->with(['owner:id,name', 'branch:id,name', 'salesProject:id,project_name'])->orderByDesc('scheduled_date')->orderByDesc('id')
            : null;
        $leads = ($salesId || ! $paginate)
            ? $this->leadBase($salesIds, $period, $scope)->with(['sales:id,name', 'branch:id,name', 'project:id,project_name', 'leadSource:id,name'])->orderByDesc('lead_date')->orderByDesc('id')
            : null;

        return [
            'period' => $period,
            'filters' => ['period' => $period['key'], 'date_from' => $period['from']->toDateString(), 'date_to' => $period['to']->toDateString(), 'coordinator_id' => $coordinatorId, 'sales_id' => $salesId],
            'coordinators' => $team['coordinators'],
            'salesUsers' => $coordinatorId ? $team['sales']->whereIn('id', array_keys($team['sales_ids_by_coordinator'][$coordinatorId] ?? []))->values() : $team['sales'],
            'kpi' => [
                'coordinator_count' => $coordinatorRows->count(),
                'sales_count' => $salesRows->count(),
                'agenda_count' => $salesRows->sum('agenda_count'),
                'agenda_done' => $salesRows->sum('agenda_done'),
                'lead_count' => $salesRows->sum('lead_count'),
                'pending_create' => $salesRows->sum('pending_create'),
                'pending_update' => $salesRows->sum('pending_update'),
                'sync_failed' => $salesRows->sum('sync_failed'),
            ],
            'attention' => [
                'without_agenda' => $salesRows->where('agenda_count', 0)->values(),
                'pending' => $salesRows->filter(fn ($row) => $row->pending_create + $row->pending_update + $row->sync_failed > 0)->values(),
                'missing_result' => $salesRows->where('missing_result', '>', 0)->values(),
            ],
            'coordinatorRows' => $coordinatorRows,
            'salesRows' => $salesRows,
            'coordinatorNamesBySalesId' => $team['coordinator_names_by_sales_id'],
            'agendas' => $agendas ? ($paginate ? $agendas->paginate(15, ['*'], 'agenda_page')->withQueryString() : $agendas->get()) : ($paginate ? null : collect()),
            'leads' => $leads ? ($paginate ? $leads->paginate(15, ['*'], 'lead_page')->withQueryString() : $leads->get()) : ($paginate ? null : collect()),
        ];
    }

    public function exportData(User $actor, array $filters): array
    {
        return $this->resolve($actor, $filters, false);
    }

    private function period(array $filters): array
    {
        $timezone = config('app.timezone');
        $today = now($timezone)->toImmutable();
        $key = in_array($filters['period'] ?? 'today', ['today', 'week', 'month', 'custom'], true) ? ($filters['period'] ?? 'today') : 'today';
        [$from, $to] = match ($key) {
            'week' => [$today->startOfWeek(), $today->endOfWeek()],
            'month' => [$today->startOfMonth(), $today->endOfMonth()],
            'custom' => [CarbonImmutable::parse($filters['date_from'] ?? $today, $timezone)->startOfDay(), CarbonImmutable::parse($filters['date_to'] ?? $today, $timezone)->endOfDay()],
            default => [$today->startOfDay(), $today->endOfDay()],
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

    private function team(User $actor, array $scope): array
    {
        $canonical = $this->salesTeamScope->displayedFor($actor, $scope['branch_ids'], $scope['project_ids']);
        $coordinators = $canonical['coordinators']->sortBy('name')->values();
        $salesIdsByCoordinator = $canonical['sales_ids_by_coordinator']->map(
            fn ($ids) => $ids->mapWithKeys(fn ($id) => [(int) $id => true])->all()
        )->all();
        $coordinatorNames = $coordinators->keyBy('id');
        $coordinatorNamesBySalesId = collect($salesIdsByCoordinator)
            ->flatMap(fn ($salesIds, $coordinatorId) => collect(array_keys($salesIds))->map(fn ($salesId) => [
                'sales_id' => $salesId,
                'name' => $coordinatorNames->get((int) $coordinatorId)?->name,
            ]))
            ->groupBy('sales_id')
            ->map(fn ($rows) => $rows->pluck('name')->filter()->unique()->sort()->values()->all())
            ->all();
        $sales = User::query()->whereIn('id', $canonical['sales']->pluck('id'))
            ->with([
                'branch:id,name',
                'assignedProjects' => fn ($query) => $query->whereIn('lead_master.id', $scope['project_ids'])
                    ->where('lead_master.is_active', true)
                    ->where('project_user.is_active', true)
                    ->where(fn ($query) => $query->whereNull('project_user.assignment_start_date')->orWhereDate('project_user.assignment_start_date', '<=', today()->toDateString()))
                    ->where(fn ($query) => $query->whereNull('project_user.assignment_end_date')->orWhereDate('project_user.assignment_end_date', '>=', today()->toDateString()))
                    ->orderByDesc('project_user.is_primary')
                    ->orderBy('lead_master.project_name'),
            ])->orderBy('name')->get(['id', 'name', 'branch_id']);
        $sales->each(function (User $user) {
            $primary = $user->assignedProjects->where('pivot.is_primary', true);
            $project = $primary->count() === 1 ? $primary->first() : ($user->assignedProjects->count() === 1 ? $user->assignedProjects->first() : null);
            $user->setAttribute('resolved_project_name', $project?->project_name);
        });

        return [
            'coordinators' => $coordinators,
            'sales' => $sales,
            'coordinator_names_by_sales_id' => $coordinatorNamesBySalesId,
            'sales_ids_by_coordinator' => $salesIdsByCoordinator,
        ];
    }

    private function agendaBase(array $salesIds, array $period, array $scope): Builder
    {
        return ContentItem::query()
            ->where('item_type', 'agenda')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->whereIn('owner_user_id', $salesIds)
            ->whereIn('branch_id', $scope['branch_ids'])
            ->whereIn('sales_project_id', $scope['project_ids'])
            ->whereDate('scheduled_date', '>=', $period['from']->toDateString())
            ->whereDate('scheduled_date', '<=', $period['to']->toDateString());
    }

    private function leadBase(array $salesIds, array $period, array $scope): Builder
    {
        return SalesLead::query()
            ->whereIn('sales_user_id', $salesIds)
            ->whereIn('branch_id', $scope['branch_ids'])
            ->whereIn('project_id', $scope['project_ids'])
            ->whereDate('lead_date', '>=', $period['from']->toDateString())
            ->whereDate('lead_date', '<=', $period['to']->toDateString());
    }
}
