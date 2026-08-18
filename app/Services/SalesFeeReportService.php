<?php

namespace App\Services;

use App\Enums\SalesLeadStatus;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SalesFeeReportService
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly WorkspaceAccessService $workspace,
    ) {}

    public function summary(User $actor, array $filters): array
    {
        $access = $this->access($actor);
        $this->authorizeFilters($filters, $access);
        $assignments = $this->assignmentQuery($access, $filters)->get();
        $salesIds = $assignments->pluck('user_id')->unique()->all();
        $projectIds = $assignments->pluck('project_id')->unique()->all();
        $agendas = $this->agendaAggregate($access['branchId'], $salesIds, $projectIds, $filters)->keyBy(fn ($row) => $row->owner_user_id.'-'.$row->sales_project_id);
        $leads = $this->leadAggregate($access['branchId'], $salesIds, $projectIds, $filters)->keyBy(fn ($row) => $row->sales_user_id.'-'.$row->project_id);
        $salesUsersById = User::query()->whereIn('id', $salesIds)->get()->keyBy('id');
        $projectsById = LeadMaster::query()->whereIn('id', $projectIds)->get()->keyBy('id');
        $coordinatorsById = User::query()->whereIn('id', $assignments->pluck('coordinator_id')->filter()->unique())->get()->keyBy('id');

        $rows = $assignments->map(function ($row) use ($agendas, $leads, $salesUsersById, $projectsById, $coordinatorsById) {
            $key = $row->user_id.'-'.$row->project_id;

            return (object) [
                'user_id' => $row->user_id,
                'sales_name' => $salesUsersById->get($row->user_id)?->name,
                'project_id' => $row->project_id,
                'project_name' => $projectsById->get($row->project_id)?->project_name,
                'coordinator_id' => $row->coordinator_id,
                'coordinator_name' => $coordinatorsById->get($row->coordinator_id)?->name,
                'agenda_total' => (int) ($agendas->get($key)->agenda_total ?? 0),
                'agenda_done' => (int) ($agendas->get($key)->agenda_done ?? 0),
                'lead_total' => (int) ($leads->get($key)->lead_total ?? 0),
            ];
        });

        return [
            'dateFrom' => $filters['date_from'],
            'dateTo' => $filters['date_to'],
            'projectId' => $filters['project_id'] ?? null,
            'coordinatorId' => $filters['coordinator_id'] ?? null,
            'salesUserId' => $filters['sales_user_id'] ?? null,
            'branch' => Branch::query()->findOrFail($access['branchId']),
            'rows' => $rows,
            'projects' => LeadMaster::query()->whereIn('id', $access['projectIds'])->orderBy('project_name')->get(),
            'coordinators' => User::query()->whereIn('id', $access['coordinatorIds'])->orderBy('name')->get(),
            'salesUsers' => User::query()->whereIn('id', $access['salesIds'])->orderBy('name')->get(),
        ];
    }

    public function detail(User $actor, User $salesUser, LeadMaster $project, array $filters): array
    {
        $access = $this->access($actor);
        $this->authorizeFilters($filters, $access);
        abort_unless(in_array((int) $salesUser->id, $access['salesIds'], true) && in_array((int) $project->id, $access['projectIds'], true), 403);
        $assignment = $this->assignmentQuery($access, [])->where('project_user.user_id', $salesUser->id)->where('project_user.project_id', $project->id)->first();
        abort_unless($assignment, 403);

        $agendas = ContentItem::query()
            ->where('branch_id', $access['branchId'])
            ->where('item_type', 'agenda')->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->where('owner_user_id', $salesUser->id)->where('sales_project_id', $project->id)
            ->whereBetween('scheduled_date', [$filters['date_from'], $filters['date_to']])
            ->orderBy('scheduled_date')->orderBy('id')->get();
        $leads = SalesLead::query()->with('project')
            ->where('branch_id', $access['branchId'])
            ->where('sales_user_id', $salesUser->id)->where('project_id', $project->id)
            ->whereBetween('lead_date', [$filters['date_from'], $filters['date_to']])
            ->orderBy('lead_date')->orderBy('id')->get();
        $lifecycleLeads = SalesLead::query()
            ->with(['statusHistories' => fn ($query) => $query
                ->whereIn('status', [SalesLeadStatus::FaceToFace->value, SalesLeadStatus::SiteVisit->value, SalesLeadStatus::Utj->value])
                ->orderBy('changed_at')
                ->orderBy('id')])
            ->where('branch_id', $access['branchId'])
            ->where('sales_user_id', $salesUser->id)
            ->where('project_id', $project->id)
            ->get();
        $lifecycle = collect([
            SalesLeadStatus::FaceToFace->value => 'met_at',
            SalesLeadStatus::SiteVisit->value => 'surveyed_at',
            SalesLeadStatus::Utj->value => 'utj_at',
        ])->mapWithKeys(function (string $field, string $status) use ($lifecycleLeads, $filters): array {
            $total = $lifecycleLeads->filter(function (SalesLead $lead) use ($field, $status, $filters): bool {
                $timestamp = $lead->{$field};
                if ($timestamp === null) {
                    $timestamp = $lead->statusHistories
                        ->firstWhere('status', $status)?->changed_at;
                }

                return $timestamp !== null
                    && $timestamp->betweenIncluded(
                        CarbonImmutable::parse($filters['date_from'])->startOfDay(),
                        CarbonImmutable::parse($filters['date_to'])->endOfDay(),
                    );
            })->count();

            return [$status => $total];
        });

        return [
            'sales' => $salesUser,
            'coordinator' => $assignment->coordinator_id ? User::query()->find($assignment->coordinator_id) : null,
            'branch' => Branch::query()->findOrFail($access['branchId']),
            'project' => $project,
            'dateFrom' => $filters['date_from'],
            'dateTo' => $filters['date_to'],
            'metrics' => [
                'total_agenda' => $agendas->count(),
                'agenda_done' => $agendas->where('status', 'done')->count(),
                'total_lead' => $leads->count(),
                'face_to_face' => (int) ($lifecycle[SalesLeadStatus::FaceToFace->value] ?? 0),
                'site_visit' => (int) ($lifecycle[SalesLeadStatus::SiteVisit->value] ?? 0),
                'utj' => (int) ($lifecycle[SalesLeadStatus::Utj->value] ?? 0),
            ],
            'agendaStatusLabels' => [
                'planned' => 'Direncanakan',
                'confirmed' => 'Dikonfirmasi',
                'done' => 'Selesai',
                'cancelled' => 'Dibatalkan',
                'rescheduled' => 'Dijadwalkan Ulang',
            ],
            'agendas' => $agendas,
            'leads' => $leads,
        ];
    }

    private function access(User $actor): array
    {
        abort_unless($actor->hasPrimaryRole('admin') && $actor->branch_id, 403);
        $branchId = (int) $actor->branch_id;
        $branchIds = array_intersect($this->scope->branchIds($actor, 'sales_pocketbook'), $this->workspace->accessibleBranchIds($actor));
        abort_unless(in_array($branchId, $branchIds, true), 403);
        $projectIds = LeadMaster::query()->where('branch_id', $branchId)->where('is_active', true)
            ->whereIn('id', array_intersect($this->scope->projectIds($actor, 'sales_pocketbook'), $this->workspace->accessibleProjectIds($actor)))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $visibleUserIds = $this->scope->visibleUserIds($actor, 'sales_pocketbook');
        $salesIds = User::query()->where('branch_id', $branchId)->where('is_active', true)->whereIn('id', $visibleUserIds)
            ->whereHas('role', fn ($query) => $query->where('slug', 'sales')->where('is_active', true))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $coordinatorIds = User::query()->where('branch_id', $branchId)->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->where('slug', 'sales_coordinator')->where('is_active', true))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return compact('branchId', 'projectIds', 'salesIds', 'coordinatorIds');
    }

    private function authorizeFilters(array $filters, array $access): void
    {
        foreach (['project_id' => 'projectIds', 'sales_user_id' => 'salesIds', 'coordinator_id' => 'coordinatorIds'] as $filter => $scope) {
            abort_if(isset($filters[$filter]) && ! in_array((int) $filters[$filter], $access[$scope], true), 403);
        }
    }

    private function assignmentQuery(array $access, array $filters): Builder
    {
        $today = today()->toDateString();
        $coordinators = DB::table('sales_coordinator_sales')
            ->join('users as coordinators', 'coordinators.id', '=', 'sales_coordinator_sales.coordinator_user_id')
            ->join('roles as coordinator_roles', 'coordinator_roles.id', '=', 'coordinators.role_id')
            ->where('sales_coordinator_sales.is_active', true)->where('coordinators.is_active', true)
            ->whereIn('coordinators.id', $access['coordinatorIds'])
            ->where('coordinator_roles.slug', 'sales_coordinator')->where('coordinator_roles.is_active', true)
            ->where(fn ($query) => $query->whereNull('started_at')->orWhereDate('started_at', '<=', $today))
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhereDate('ended_at', '>=', $today))
            ->select('sales_user_id', DB::raw('MIN(coordinators.id) as coordinator_id'), DB::raw('MIN(coordinators.name) as coordinator_name'))
            ->groupBy('sales_user_id');

        return DB::table('project_user')->join('users', 'users.id', '=', 'project_user.user_id')
            ->join('lead_master', 'lead_master.id', '=', 'project_user.project_id')
            ->leftJoinSub($coordinators, 'current_coordinators', fn ($join) => $join->on('current_coordinators.sales_user_id', '=', 'users.id'))
            ->whereIn('users.id', $access['salesIds'])->whereIn('lead_master.id', $access['projectIds'])
            ->where('project_user.is_active', true)->where('users.is_active', true)->where('lead_master.is_active', true)
            ->where(fn ($query) => $query->whereNull('assignment_start_date')->orWhereDate('assignment_start_date', '<=', $today))
            ->where(fn ($query) => $query->whereNull('assignment_end_date')->orWhereDate('assignment_end_date', '>=', $today))
            ->when($filters['project_id'] ?? null, fn ($query, $id) => $query->where('lead_master.id', $id))
            ->when($filters['sales_user_id'] ?? null, fn ($query, $id) => $query->where('users.id', $id))
            ->when($filters['coordinator_id'] ?? null, fn ($query, $id) => $query->where('current_coordinators.coordinator_id', $id))
            ->select('users.id as user_id', 'users.name as sales_name', 'lead_master.id as project_id', 'lead_master.project_name', 'current_coordinators.coordinator_id', 'current_coordinators.coordinator_name')
            ->distinct()->orderBy('users.name')->orderBy('lead_master.project_name');
    }

    private function agendaAggregate(int $branchId, array $salesIds, array $projectIds, array $filters)
    {
        return ContentItem::query()->where('branch_id', $branchId)
            ->where('item_type', 'agenda')->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->whereIn('owner_user_id', $salesIds)->whereIn('sales_project_id', $projectIds)
            ->whereBetween('scheduled_date', [$filters['date_from'], $filters['date_to']])
            ->select('owner_user_id', 'sales_project_id', DB::raw('COUNT(*) as agenda_total'), DB::raw("SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as agenda_done"))
            ->groupBy('owner_user_id', 'sales_project_id')->get();
    }

    private function leadAggregate(int $branchId, array $salesIds, array $projectIds, array $filters)
    {
        return SalesLead::query()->where('branch_id', $branchId)
            ->whereIn('sales_user_id', $salesIds)->whereIn('project_id', $projectIds)
            ->whereBetween('lead_date', [$filters['date_from'], $filters['date_to']])
            ->select('sales_user_id', 'project_id', DB::raw('COUNT(*) as lead_total'))
            ->groupBy('sales_user_id', 'project_id')->get();
    }
}
