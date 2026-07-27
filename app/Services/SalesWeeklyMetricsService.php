<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\SalesLead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SalesWeeklyMetricsService
{
    public const METRIC_COLUMNS = [
        'lead_new' => 'lead_date',
        'contacted' => 'contacted_at',
        'met' => 'met_at',
        'surveyed' => 'surveyed_at',
        'utj' => 'utj_at',
        'documents_completed' => 'documents_completed_at',
        'akad' => 'akad_at',
    ];

    public function __construct(private readonly WorkspaceAccessService $workspaceAccess) {}

    public function period(?string $week = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $timezone = config('app.timezone');
        if ($dateFrom && $dateTo) {
            $start = CarbonImmutable::parse($dateFrom, $timezone)->startOfDay();
            $end = CarbonImmutable::parse($dateTo, $timezone)->endOfDay();
        } else {
            $date = CarbonImmutable::parse($week ?: 'today', $timezone);
            $start = $date->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
            $end = $date->endOfWeek(CarbonImmutable::SUNDAY)->endOfDay();
        }

        return compact('start', 'end');
    }

    public function metrics(User $viewer, array $period, array $filters = []): array
    {
        $leadQuery = $this->leadQuery($viewer, $filters);
        $metrics = [];
        foreach (self::METRIC_COLUMNS as $metric => $column) {
            $metrics[$metric] = $column === 'lead_date'
                ? (clone $leadQuery)->whereDate($column, '>=', $period['start']->toDateString())
                    ->whereDate($column, '<=', $period['end']->toDateString())->count()
                : (clone $leadQuery)->whereBetween($column, [$period['start'], $period['end']])->count();
        }

        $agendaQuery = $this->agendaQuery($viewer, $filters);
        $metrics['agenda_completed'] = (clone $agendaQuery)
            ->where('status', 'done')
            ->whereBetween('completed_at', [$period['start'], $period['end']])
            ->count();
        $metrics['conversions'] = [
            'lead_contacted' => $this->conversion($metrics['contacted'], $metrics['lead_new']),
            'contacted_met' => $this->conversion($metrics['met'], $metrics['contacted']),
            'met_survey' => $this->conversion($metrics['surveyed'], $metrics['met']),
            'survey_utj' => $this->conversion($metrics['utj'], $metrics['surveyed']),
            'utj_documents' => $this->conversion($metrics['documents_completed'], $metrics['utj']),
            'documents_akad' => $this->conversion($metrics['akad'], $metrics['documents_completed']),
        ];

        $leadInput = (clone $leadQuery)->max('created_at');
        $agendaInput = (clone $agendaQuery)->max('created_at');
        $lastInput = collect([$leadInput, $agendaInput])->filter()->max();
        $metrics['last_input'] = $lastInput ? CarbonImmutable::parse($lastInput, config('app.timezone')) : null;

        return $metrics;
    }

    public function monitoringRows(User $viewer, array $period, Collection $salesUsers, Collection $projects, array $filters = []): Collection
    {
        $projectsById = $projects->keyBy('id');
        $rows = collect();

        foreach ($salesUsers as $sales) {
            if (! empty($filters['sales_user_id']) && (int) $sales->id !== (int) $filters['sales_user_id']) {
                continue;
            }

            foreach ($sales->assignedProjects as $project) {
                if (! $projectsById->has($project->id)
                    || (! empty($filters['branch_id']) && (int) $project->branch_id !== (int) $filters['branch_id'])
                    || (! empty($filters['project_id']) && (int) $project->id !== (int) $filters['project_id'])) {
                    continue;
                }

                $scope = ['branch_id' => $project->branch_id, 'project_id' => $project->id, 'sales_user_id' => $sales->id];
                $rows->push(array_merge([
                    'sales' => $sales,
                    'branch' => $project->branch,
                    'project' => $project,
                    'scope' => $scope,
                ], $this->metrics($viewer, $period, $scope)));
            }
        }

        return $rows;
    }

    public function reminders(User $sales, array $filters = []): array
    {
        $today = CarbonImmutable::now(config('app.timezone'));
        $agendas = $this->agendaQuery($sales, $filters);
        $leads = $this->leadQuery($sales, $filters);
        $staleThreshold = $today->subDays(3);

        $duplicateGroups = (clone $leads)->whereNotNull('normalized_phone')
            ->select('normalized_phone')->groupBy('normalized_phone')->havingRaw('COUNT(*) > 1')->get()->count();

        return [
            'no_agenda_today' => ! (clone $agendas)->whereDate('scheduled_date', $today->toDateString())
                ->whereNotIn('status', ['cancelled', 'rescheduled'])->exists(),
            'done_without_result' => (clone $agendas)->where('status', 'done')
                ->where(fn (Builder $query) => $query->whereNull('activity_result')->orWhere('activity_result', ''))->count(),
            'never_contacted' => (clone $leads)->whereNull('contacted_at')->count(),
            'stale_progress' => (clone $leads)->whereNull('akad_at')->get()
                ->filter(fn (SalesLead $lead) => $lead->lastActivityAt()?->lte($staleThreshold))->count(),
            'duplicate_phone_groups' => $duplicateGroups,
        ];
    }

    public function leadQuery(User $viewer, array $filters = []): Builder
    {
        return SalesLead::query()->visibleTo($viewer)
            ->when(! empty($filters['branch_id']), fn (Builder $query) => $query->where('branch_id', $filters['branch_id']))
            ->when(! empty($filters['project_id']), fn (Builder $query) => $query->where('project_id', $filters['project_id']))
            ->when(! empty($filters['sales_user_id']), fn (Builder $query) => $query->where('sales_user_id', $filters['sales_user_id']));
    }

    public function agendaQuery(User $viewer, array $filters = []): Builder
    {
        return ContentItem::query()
            ->where('item_type', 'agenda')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->when($viewer->hasRole('sales'), fn (Builder $query) => $query->where('owner_user_id', $viewer->id))
            ->when(! $viewer->canViewAllBranches() && ! $viewer->hasRole('sales'), fn (Builder $query) => $query->whereIn('branch_id', $this->workspaceAccess->accessibleBranchIds($viewer)))
            ->when(! empty($filters['branch_id']), fn (Builder $query) => $query->where('branch_id', $filters['branch_id']))
            ->when(! empty($filters['project_id']), fn (Builder $query) => $query->where('sales_project_id', $filters['project_id']))
            ->when(! empty($filters['sales_user_id']), fn (Builder $query) => $query->where('owner_user_id', $filters['sales_user_id']));
    }

    private function conversion(int $numerator, int $denominator): ?float
    {
        return $denominator === 0 ? null : round($numerator / $denominator * 100, 1);
    }
}
