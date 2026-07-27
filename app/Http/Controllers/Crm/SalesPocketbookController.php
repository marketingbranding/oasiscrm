<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\LeadSource;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\SalesWeeklyMetricsService;
use App\Services\WorkspaceAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class SalesPocketbookController extends Controller
{
    public function __construct(
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly SalesWeeklyMetricsService $weeklyMetrics,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', SalesLead::class);
        $user = $request->user();
        $request->validate([
            'week' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date', 'required_with:date_to'],
            'date_to' => ['nullable', 'date', 'required_with:date_from', 'after_or_equal:date_from'],
            'sort' => ['nullable', 'in:sales,branch,project,lead_new,contacted,met,surveyed,utj,documents_completed,akad,agenda_completed,last_input'],
            'direction' => ['nullable', 'in:asc,desc'],
            'report_metric' => ['nullable', 'in:'.implode(',', array_keys(SalesWeeklyMetricsService::METRIC_COLUMNS))],
        ]);
        $tab = in_array($request->query('tab'), ['leads', 'agenda', 'report'], true) ? $request->query('tab') : 'leads';
        $monitoring = ! $user->hasRole('sales');
        $branches = $this->workspaceAccess->accessibleBranches($user);
        $projects = $this->workspaceAccess->accessibleProjects($user);
        $projects->load('assignedUsers:id');
        $selectedBranchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;
        if ($selectedBranchId && ! $branches->contains('id', $selectedBranchId)) {
            abort(403);
        }
        $selectedProjectId = $request->filled('project_id') ? $request->integer('project_id') : null;
        if ($selectedProjectId && ! $projects->contains('id', $selectedProjectId)) {
            abort(403);
        }

        $salesUsers = User::query()->where('is_active', true)
            ->whereHas('role', fn (Builder $query) => $query->where('slug', 'sales'))
            ->when(! $user->canViewAllBranches(), function (Builder $query) use ($user) {
                $branchIds = $this->workspaceAccess->accessibleBranchIds($user);
                $query->whereHas('assignedProjects', fn (Builder $projects) => $projects->whereIn('branch_id', $branchIds));
            })
            ->when($user->hasRole('sales'), fn (Builder $query) => $query->whereKey($user->id))
            ->with(['assignedProjects.branch'])->orderBy('name')->get(['id', 'name', 'branch_id']);
        if ($monitoring && $request->filled('sales_user_id') && ! $salesUsers->contains('id', $request->integer('sales_user_id'))) {
            abort(403);
        }

        $reportMetric = $request->query('report_metric');
        $leads = SalesLead::query()->visibleTo($user)
            ->with(['branch:id,name', 'project:id,project_name', 'sales:id,name', 'leadSource:id,name'])
            ->when($selectedBranchId, fn (Builder $query) => $query->where('branch_id', $selectedBranchId))
            ->when($selectedProjectId, fn (Builder $query) => $query->where('project_id', $selectedProjectId))
            ->when($monitoring && $request->filled('sales_user_id'), fn (Builder $query) => $query->where('sales_user_id', $request->integer('sales_user_id')))
            ->when($request->filled('lead_source_id'), fn (Builder $query) => $query->where('lead_source_id', $request->integer('lead_source_id')))
            ->when($request->filled('date_from'), fn (Builder $query) => $query->whereDate($reportMetric ? SalesWeeklyMetricsService::METRIC_COLUMNS[$reportMetric] : 'lead_date', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn (Builder $query) => $query->whereDate($reportMetric ? SalesWeeklyMetricsService::METRIC_COLUMNS[$reportMetric] : 'lead_date', '<=', $request->query('date_to')))
            ->when($request->filled('stage'), function (Builder $query) use ($request) {
                $stage = (string) $request->query('stage');
                abort_unless(array_key_exists($stage, SalesLead::STAGES), 422);
                $query->whereNotNull($stage);
                $later = array_slice(SalesLead::STAGE_ORDER, array_search($stage, SalesLead::STAGE_ORDER, true) + 1);
                foreach ($later as $laterStage) {
                    $query->whereNull($laterStage);
                }
            })
            ->latest('lead_date')->latest('id')->paginate(20)->withQueryString();

        $agendaDateFrom = $request->query('date_from', now()->startOfMonth()->toDateString());
        $agendaDateTo = $request->query('date_to', now()->endOfMonth()->toDateString());
        $completedAgendaDrilldown = $request->boolean('report_agenda_completed');
        $agendas = ContentItem::query()
            ->where('item_type', 'agenda')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->with(['branch:id,name', 'owner:id,name', 'rescheduledFrom:id,scheduled_date'])
            ->when($user->hasRole('sales'), fn (Builder $query) => $query->where('owner_user_id', $user->id))
            ->when(! $user->canViewAllBranches() && ! $user->hasRole('sales'), fn (Builder $query) => $query->whereIn('branch_id', $this->workspaceAccess->accessibleBranchIds($user)))
            ->when($selectedBranchId, fn (Builder $query) => $query->where('branch_id', $selectedBranchId))
            ->when($selectedProjectId, fn (Builder $query) => $query->where('sales_project_id', $selectedProjectId))
            ->when($monitoring && $request->filled('sales_user_id'), fn (Builder $query) => $query->where('owner_user_id', $request->integer('sales_user_id')))
            ->when($completedAgendaDrilldown, fn (Builder $query) => $query->where('status', 'done'))
            ->when($agendaDateFrom, fn (Builder $query) => $query->whereDate($completedAgendaDrilldown ? 'completed_at' : 'scheduled_date', '>=', $agendaDateFrom))
            ->when($agendaDateTo, fn (Builder $query) => $query->whereDate($completedAgendaDrilldown ? 'completed_at' : 'scheduled_date', '<=', $agendaDateTo))
            ->orderBy('scheduled_date')->orderBy('start_time')->paginate(20, ['*'], 'agenda_page')->withQueryString();

        $defaultProject = $this->workspaceAccess->resolveRequestedProject($user, $request->query('project_id'));
        $reportPeriod = $this->weeklyMetrics->period($request->query('week'), $request->query('date_from'), $request->query('date_to'));
        $reportFilters = array_filter([
            'branch_id' => $selectedBranchId,
            'project_id' => $selectedProjectId,
            'sales_user_id' => $monitoring && $request->filled('sales_user_id') ? $request->integer('sales_user_id') : ($user->hasRole('sales') ? $user->id : null),
        ]);
        $reportSummary = $this->weeklyMetrics->metrics($user, $reportPeriod, $reportFilters);
        $reportRows = collect();
        if ($monitoring) {
            $reportRows = $this->weeklyMetrics->monitoringRows($user, $reportPeriod, $salesUsers, $projects, $reportFilters);
            $sort = in_array($request->query('sort'), ['sales', 'branch', 'project', 'lead_new', 'contacted', 'met', 'surveyed', 'utj', 'documents_completed', 'akad', 'agenda_completed', 'last_input'], true)
                ? $request->query('sort') : 'sales';
            $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';
            $reportRows = $reportRows->sortBy(function (array $row) use ($sort) {
                return match ($sort) {
                    'sales', 'branch', 'project' => mb_strtolower($row[$sort]->{match ($sort) {
                        'project' => 'project_name', default => 'name'
                    }}),
                    'last_input' => $row['last_input']?->timestamp ?? 0,
                    default => $row[$sort],
                };
            }, SORT_REGULAR, $direction === 'desc')->values();
        }

        return view('crm.sales-pocketbook.index', [
            'tab' => $tab,
            'monitoring' => $monitoring,
            'branches' => $branches,
            'projects' => $projects,
            'salesUsers' => $salesUsers,
            'leadSources' => LeadSource::where('is_active', true)->orderBy('name')->get(),
            'leads' => $leads,
            'agendas' => $agendas,
            'agendaDateFrom' => $agendaDateFrom,
            'agendaDateTo' => $agendaDateTo,
            'defaultProject' => $defaultProject,
            'selectedBranchId' => $selectedBranchId,
            'selectedProjectId' => $selectedProjectId,
            'canCreate' => $user->can('create', SalesLead::class),
            'reportPeriod' => $reportPeriod,
            'reportSummary' => $reportSummary,
            'reportRows' => $reportRows,
        ]);
    }
}
