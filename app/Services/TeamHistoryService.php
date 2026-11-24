<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\ContentItem;
use App\Models\SalesLead;
use App\Models\SalesLeadStageHistory;
use App\Models\SalesLeadStatusHistory;
use App\Models\SalesLeadTransfer;
use App\Models\User;
use App\Support\FeatureFlag;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class TeamHistoryService
{
    public function __construct(
        private readonly OrganizationGraphService $graph,
        private readonly OrganizationScopeService $scope,
    ) {}

    /** @return array<string, mixed> */
    public function index(User $viewer, array $filters): array
    {
        abort_unless(FeatureFlag::enabled('org_hierarchical_monitoring'), Response::HTTP_NOT_FOUND);
        abort_unless($this->canMonitor($viewer), Response::HTTP_FORBIDDEN);

        $descendantIds = $this->graph->descendantIds($viewer);
        abort_if($descendantIds === [], Response::HTTP_FORBIDDEN);
        $directIds = $this->graph->directChildren($viewer)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $selected = isset($filters['user_id']) ? (int) $filters['user_id'] : null;
        if ($selected !== null && ! in_array($selected, $directIds, true)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $userIds = $selected === null ? $descendantIds : [$selected, ...$this->graph->descendantIds(User::query()->findOrFail($selected))];
        $leadBranchIds = $this->scope->branchIds($viewer, 'sales_pocketbook', 'view');
        $leadProjectIds = $this->accessibleProjectIds($viewer, 'sales_pocketbook', $userIds);
        $agendaBranchIds = $this->scope->branchIds($viewer, 'work_planner', 'view');
        $agendaProjectIds = $this->accessibleProjectIds($viewer, 'work_planner', $userIds);
        $from = CarbonImmutable::parse($filters['date_from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($filters['date_to'] ?? now()->toDateString())->endOfDay();

        $rows = $this->queryRows(
            $userIds,
            $directIds,
            $leadBranchIds,
            $leadProjectIds,
            $agendaBranchIds,
            $agendaProjectIds,
            $from,
            $to,
            $filters['type'] ?? null,
        )->orderByDesc('happened_at')->paginate(50)->withQueryString();

        return [
            'rows' => $rows,
            'directUsers' => User::query()->whereIn('id', $directIds)->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
            'from' => $from,
            'to' => $to,
        ];
    }

    private function canMonitor(User $viewer): bool
    {
        return $viewer->hasPermission('sales_pocketbook.view_team') || $viewer->hasPermission('sales_pocketbook.view_branch');
    }

    /** @param array<int> $userIds @return array<int> */
    private function accessibleProjectIds(User $viewer, string $module, array $userIds): array
    {
        $projectIds = $this->scope->projectIds($viewer, $module, 'view');
        $assignedProjectIds = DB::table('project_user')
            ->join('lead_master', 'lead_master.id', '=', 'project_user.project_id')
            ->whereIn('project_user.user_id', $userIds)
            ->where('project_user.is_active', true)
            ->where('lead_master.is_active', true)
            ->pluck('project_user.project_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_unique([...$projectIds, ...$assignedProjectIds]));
    }

    /** @param array<int> $userIds @param array<int> $directIds @param array<int> $leadBranchIds @param array<int> $leadProjectIds @param array<int> $agendaBranchIds @param array<int> $agendaProjectIds */
    private function queryRows(array $userIds, array $directIds, array $leadBranchIds, array $leadProjectIds, array $agendaBranchIds, array $agendaProjectIds, CarbonImmutable $from, CarbonImmutable $to, ?string $type): QueryBuilder
    {
        $sources = [
            'lead_stage' => $this->leadStageQuery($userIds, $leadBranchIds, $leadProjectIds, $from, $to),
            'lead_status' => $this->leadStatusQuery($userIds, $leadBranchIds, $leadProjectIds, $from, $to),
            'lead_transfer' => $this->leadTransferQuery($userIds, $leadBranchIds, $leadProjectIds, $from, $to),
            'lead_note' => $this->leadNoteQuery($userIds, $leadBranchIds, $leadProjectIds, $from, $to),
            'agenda' => $this->agendaQuery($userIds, $agendaBranchIds, $agendaProjectIds, $from, $to),
            'agenda_report' => $this->agendaReportQuery($userIds, $agendaBranchIds, $agendaProjectIds, $from, $to),
        ];
        $selected = $type === null ? array_values($sources) : [$sources[$type]];
        $union = array_shift($selected);
        foreach ($selected as $source) {
            $union->unionAll($source);
        }

        $direct = DB::query()->fromSub($union, 'team_history_events')
            ->whereIn('owner_id', $directIds)
            ->select(['type', 'happened_at', 'actor_id', 'subject_id', 'summary', 'owner_id', 'owner_name']);
        $distant = DB::query()->fromSub($union, 'team_history_events')
            ->whereNotIn('owner_id', $directIds)
            ->selectRaw("'summary' AS type, DATE(happened_at) AS happened_at, NULL AS actor_id, NULL AS subject_id, CONCAT(COUNT(CASE WHEN type LIKE 'lead_%' THEN 1 END), ' Lead diperbarui, ', COUNT(CASE WHEN type LIKE 'agenda%' THEN 1 END), ' agenda dilaporkan') AS summary, owner_id, owner_name")
            ->groupByRaw('owner_id, owner_name, DATE(happened_at)');

        return DB::query()->fromSub($direct->unionAll($distant), 'team_history')->select('*');
    }

    private function leadStageQuery(array $userIds, array $branchIds, array $projectIds, CarbonImmutable $from, CarbonImmutable $to): QueryBuilder
    {
        return SalesLeadStageHistory::query()->selectRaw("'lead_stage' AS type, h.changed_at AS happened_at, h.actor_id, h.sales_lead_id AS subject_id, CONCAT('Tahap Lead: ', l.customer_name, ' — ', COALESCE(h.to_stage, '')) AS summary, l.sales_user_id AS owner_id, u.name AS owner_name")
            ->from('sales_lead_stage_histories AS h')->join('sales_leads AS l', 'l.id', '=', 'h.sales_lead_id')->join('users AS u', 'u.id', '=', 'l.sales_user_id')
            ->whereIn('l.sales_user_id', $userIds)->whereIn('l.branch_id', $branchIds)->whereIn('l.project_id', $projectIds)->whereBetween('h.changed_at', [$from, $to])->toBase();
    }

    private function leadStatusQuery(array $userIds, array $branchIds, array $projectIds, CarbonImmutable $from, CarbonImmutable $to): QueryBuilder
    {
        return SalesLeadStatusHistory::query()->selectRaw("'lead_status' AS type, h.changed_at AS happened_at, h.actor_id, h.sales_lead_id AS subject_id, CONCAT('Status Lead: ', l.customer_name, ' — ', COALESCE(h.status, '')) AS summary, l.sales_user_id AS owner_id, u.name AS owner_name")
            ->from('sales_lead_status_histories AS h')->join('sales_leads AS l', 'l.id', '=', 'h.sales_lead_id')->join('users AS u', 'u.id', '=', 'l.sales_user_id')
            ->whereIn('l.sales_user_id', $userIds)->whereIn('l.branch_id', $branchIds)->whereIn('l.project_id', $projectIds)->whereBetween('h.changed_at', [$from, $to])->toBase();
    }

    private function leadTransferQuery(array $userIds, array $branchIds, array $projectIds, CarbonImmutable $from, CarbonImmutable $to): QueryBuilder
    {
        $columns = "'lead_transfer' AS type, t.created_at AS happened_at, t.actor_user_id AS actor_id, t.sales_lead_id AS subject_id, 'Alih Lead' AS summary, t.from_sales_user_id AS owner_id, u.name AS owner_name";
        $base = fn () => SalesLeadTransfer::query()
            ->from('sales_lead_transfers AS t')
            ->join('sales_leads AS l', 'l.id', '=', 't.sales_lead_id')
            ->whereIn('l.branch_id', $branchIds)
            ->whereIn('l.project_id', $projectIds)
            ->whereBetween('t.created_at', [$from, $to]);

        $outgoing = $base()->selectRaw($columns)->join('users AS u', 'u.id', '=', 't.from_sales_user_id')->whereIn('t.from_sales_user_id', $userIds);
        $incoming = $base()->selectRaw(str_replace('t.from_sales_user_id AS owner_id', 't.to_sales_user_id AS owner_id', $columns))->join('users AS u', 'u.id', '=', 't.to_sales_user_id')->whereIn('t.to_sales_user_id', $userIds);

        return $outgoing->unionAll($incoming)->toBase();
    }

    private function leadNoteQuery(array $userIds, array $branchIds, array $projectIds, CarbonImmutable $from, CarbonImmutable $to): QueryBuilder
    {
        return Comment::query()->selectRaw("'lead_note' AS type, c.created_at AS happened_at, c.user_id AS actor_id, c.commentable_id AS subject_id, 'Catatan Lead' AS summary, l.sales_user_id AS owner_id, u.name AS owner_name")
            ->from('comments AS c')->join('sales_leads AS l', function ($join): void {
                $join->on('l.id', '=', 'c.commentable_id')->where('c.commentable_type', SalesLead::class);
            })->join('users AS u', 'u.id', '=', 'l.sales_user_id')
            ->whereIn('l.sales_user_id', $userIds)->whereIn('l.branch_id', $branchIds)->whereIn('l.project_id', $projectIds)->whereBetween('c.created_at', [$from, $to])->toBase();
    }

    private function agendaQuery(array $userIds, array $branchIds, array $projectIds, CarbonImmutable $from, CarbonImmutable $to): QueryBuilder
    {
        return $this->agendaBaseQuery($userIds, $branchIds, $projectIds, $from, $to, false);
    }

    private function agendaReportQuery(array $userIds, array $branchIds, array $projectIds, CarbonImmutable $from, CarbonImmutable $to): QueryBuilder
    {
        return $this->agendaBaseQuery($userIds, $branchIds, $projectIds, $from, $to, true);
    }

    private function agendaBaseQuery(array $userIds, array $branchIds, array $projectIds, CarbonImmutable $from, CarbonImmutable $to, bool $report): QueryBuilder
    {
        $type = $report ? 'agenda_report' : 'agenda';
        $condition = $report ? "(c.status = 'done' OR TRIM(COALESCE(c.activity_result, '')) <> '')" : "(c.status <> 'done' AND TRIM(COALESCE(c.activity_result, '')) = '')";

        return ContentItem::query()->selectRaw("'{$type}' AS type, COALESCE(c.updated_at, c.created_at) AS happened_at, c.updated_by AS actor_id, c.id AS subject_id, CONCAT('Agenda: ', c.title) AS summary, c.owner_user_id AS owner_id, u.name AS owner_name")
            ->from('content_items AS c')->join('users AS u', 'u.id', '=', 'c.owner_user_id')->where('c.agenda_type', ContentItem::SALES_AGENDA_TYPE)->whereRaw($condition)
            ->whereIn('c.owner_user_id', $userIds)->whereIn('c.branch_id', $branchIds)->whereIn('c.sales_project_id', $projectIds)->whereBetween(DB::raw('COALESCE(c.updated_at, c.created_at)'), [$from, $to])->toBase();
    }
}
