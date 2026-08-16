<?php

namespace App\Services;

use App\Enums\SalesLeadStatus;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class AdminBranchSalesMonitoringService
{
    public function __construct(
        private readonly OrganizationScopeService $organizationScope,
        private readonly WorkspaceAccessService $workspaceAccess,
    ) {}

    public function resolve(User $actor, array $filters): array
    {
        $scope = $this->scope($actor);
        $period = $this->period($filters);
        $projectId = filled($filters['project_id'] ?? null) ? (int) $filters['project_id'] : null;
        $coordinatorId = filled($filters['coordinator_id'] ?? null) ? (int) $filters['coordinator_id'] : null;
        $salesUserId = filled($filters['sales_user_id'] ?? null) ? (int) $filters['sales_user_id'] : null;

        abort_if(filled($filters['branch_id'] ?? null) && (int) $filters['branch_id'] !== $scope['branch_id'], 403);
        abort_if($projectId && ! in_array($projectId, $scope['project_ids'], true), 403);

        $team = $this->team($scope);
        abort_if($coordinatorId && ! $team['coordinators']->contains('id', $coordinatorId), 403);
        abort_if($salesUserId && ! $team['sales']->contains('id', $salesUserId), 403);
        abort_if($coordinatorId && $salesUserId && ! in_array($salesUserId, $team['sales_ids_by_coordinator'][$coordinatorId] ?? [], true), 403);

        $salesIds = $team['sales']->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($coordinatorId) {
            $salesIds = array_values(array_intersect($salesIds, $team['sales_ids_by_coordinator'][$coordinatorId] ?? []));
        }
        if ($salesUserId) {
            $salesIds = [$salesUserId];
        }

        $leads = $this->leadQuery($scope, $period, $salesIds, $filters, $projectId)
            ->with([
                'branch:id,name',
                'project:id,project_name',
                'sales:id,name',
                'latestStatusHistory' => fn ($query) => $query->select([
                    'sales_lead_status_histories.id',
                    'sales_lead_status_histories.sales_lead_id',
                    'sales_lead_status_histories.status',
                    'sales_lead_status_histories.changed_at',
                ]),
            ])
            ->orderByDesc('lead_date')->orderByDesc('id')
            ->paginate(20, ['*'], 'lead_page')->withQueryString();
        $leads->getCollection()->each(function (SalesLead $lead) {
            $lead->setAttribute('latest_activity_status', $lead->latestStatusHistory?->status ?? $lead->current_status);
            $lead->setAttribute('latest_activity_at', $lead->latestStatusHistory?->changed_at ?? $lead->current_status_changed_at ?? $lead->updated_at);
        });

        $agendas = $this->agendaQuery($scope, $period, $salesIds, $filters, $projectId)
            ->with(['owner:id,name', 'salesProject:id,project_name', 'evidence'])
            ->orderByDesc('scheduled_date')->orderByDesc('id')
            ->paginate(20, ['*'], 'agenda_page')->withQueryString();

        return [
            'tab' => $filters['tab'] ?? 'leads',
            'period' => $period,
            'filters' => $filters + ['period' => $period['month'], 'date_from' => $period['from']->toDateString(), 'date_to' => $period['to']->toDateString()],
            'branches' => $this->workspaceAccess->accessibleBranches($actor)->where('id', $scope['branch_id'])->values(),
            'projects' => LeadMaster::query()->whereIn('id', $scope['project_ids'])->orderBy('project_name')->get(['id', 'branch_id', 'project_name']),
            'coordinators' => $team['coordinators'],
            'salesUsers' => $team['sales'],
            'salesIdsByCoordinator' => $team['sales_ids_by_coordinator'],
            'coordinatorNamesBySalesId' => $team['coordinator_names_by_sales_id'],
            'sourceOptions' => $this->distinctLeadOptions($scope, 'source'),
            'platformOptions' => $this->distinctLeadOptions($scope, 'platform'),
            'statusOptions' => collect(SalesLeadStatus::cases())->map(fn ($status) => $status->value)->all(),
            'agendaCategoryOptions' => ContentItem::SALES_ACTIVITY_CATEGORIES,
            'agendaStatusOptions' => ContentItem::STATUSES['agenda'],
            'leads' => $leads,
            'agendas' => $agendas,
        ];
    }

    private function scope(User $actor): array
    {
        abort_unless($actor->branch_id, 403);
        $branchIds = array_values(array_intersect(
            [(int) $actor->branch_id],
            $this->organizationScope->branchIds($actor, 'sales_pocketbook', 'view'),
            $this->workspaceAccess->accessibleBranchIds($actor),
        ));
        abort_unless($branchIds === [(int) $actor->branch_id], 403);

        return [
            'branch_id' => (int) $actor->branch_id,
            'project_ids' => array_values(array_intersect(
                $this->organizationScope->projectIds($actor, 'sales_pocketbook', 'view'),
                $this->workspaceAccess->accessibleProjectIds($actor),
                LeadMaster::query()->where('branch_id', $actor->branch_id)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            )),
            'user_ids' => $this->organizationScope->visibleUserIds($actor, 'sales_pocketbook', 'view'),
        ];
    }

    private function team(array $scope): array
    {
        $sales = User::query()->where('is_active', true)->whereIn('id', $scope['user_ids'])->where('branch_id', $scope['branch_id'])
            ->whereHas('role', fn (Builder $query) => $query->where('slug', 'sales'))
            ->whereHas('assignedProjects', fn (Builder $query) => $query->whereIn('lead_master.id', $scope['project_ids']))
            ->orderBy('name')->get(['id', 'name', 'branch_id']);
        $assignments = SalesCoordinatorSales::query()->current()->withValidRoles()
            ->whereIn('sales_user_id', $sales->pluck('id'))
            ->whereHas('coordinator', fn (Builder $query) => $query->where('is_active', true)->where('branch_id', $scope['branch_id'])->whereIn('id', $scope['user_ids']))
            ->with('coordinator:id,name,branch_id')->get();
        $coordinators = $assignments->pluck('coordinator')->filter()->unique('id')->sortBy('name')->values();
        $salesIdsByCoordinator = $assignments->groupBy('coordinator_user_id')->map(fn ($rows) => $rows->pluck('sales_user_id')->map(fn ($id) => (int) $id)->unique()->values()->all())->all();
        $coordinatorNamesBySalesId = $assignments->groupBy('sales_user_id')->map(fn ($rows) => $rows->pluck('coordinator.name')->filter()->unique()->sort()->values()->all())->all();

        return [
            'sales' => $sales,
            'coordinators' => $coordinators,
            'sales_ids_by_coordinator' => $salesIdsByCoordinator,
            'coordinator_names_by_sales_id' => $coordinatorNamesBySalesId,
        ];
    }

    private function period(array $filters): array
    {
        $timezone = config('app.timezone');
        $month = $filters['period'] ?? now($timezone)->format('Y-m');
        $base = CarbonImmutable::createFromFormat('!Y-m', $month, $timezone);
        $from = filled($filters['date_from'] ?? null) ? CarbonImmutable::parse($filters['date_from'], $timezone)->startOfDay() : $base->startOfMonth();
        $to = filled($filters['date_to'] ?? null) ? CarbonImmutable::parse($filters['date_to'], $timezone)->endOfDay() : $base->endOfMonth();
        abort_if($from->gt($to), 422);

        return compact('month', 'from', 'to');
    }

    private function leadQuery(array $scope, array $period, array $salesIds, array $filters, ?int $projectId): Builder
    {
        return SalesLead::query()->where('branch_id', $scope['branch_id'])->whereIn('project_id', $scope['project_ids'])->whereIn('sales_user_id', $salesIds)
            ->whereDate('lead_date', '>=', $period['from']->toDateString())->whereDate('lead_date', '<=', $period['to']->toDateString())
            ->when($projectId, fn (Builder $query) => $query->where('project_id', $projectId))
            ->when(filled($filters['source'] ?? null), fn (Builder $query) => $query->whereEffectiveSource($filters['source']))
            ->when(filled($filters['platform'] ?? null), fn (Builder $query) => $query->where('platform', $filters['platform']))
            ->when(filled($filters['status'] ?? null), fn (Builder $query) => $query->where('current_status', $filters['status']));
    }

    private function agendaQuery(array $scope, array $period, array $salesIds, array $filters, ?int $projectId): Builder
    {
        return ContentItem::query()->where('item_type', 'agenda')->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->where('branch_id', $scope['branch_id'])->whereIn('sales_project_id', $scope['project_ids'])->whereIn('owner_user_id', $salesIds)
            ->whereDate('scheduled_date', '>=', $period['from']->toDateString())->whereDate('scheduled_date', '<=', $period['to']->toDateString())
            ->when($projectId, fn (Builder $query) => $query->where('sales_project_id', $projectId))
            ->when(filled($filters['agenda_category'] ?? null), fn (Builder $query) => $query->where('sales_activity_category', $filters['agenda_category']))
            ->when(filled($filters['agenda_status'] ?? null), fn (Builder $query) => $query->where('status', $filters['agenda_status']));
    }

    private function distinctLeadOptions(array $scope, string $column): array
    {
        return SalesLead::query()->where('branch_id', $scope['branch_id'])->whereIn('project_id', $scope['project_ids'])
            ->whereNotNull($column)->where($column, '<>', '')->distinct()->orderBy($column)->pluck($column)->all();
    }
}
