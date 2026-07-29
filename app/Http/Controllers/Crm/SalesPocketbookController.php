<?php

namespace App\Http\Controllers\Crm;

use App\Exports\SalesPocketbookExport;
use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\LeadSource;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\OrganizationScopeService;
use App\Services\SalesDailyReminderService;
use App\Services\SalesWeeklyMetricsService;
use App\Services\WorkspaceAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesPocketbookController extends Controller
{
    public function __construct(
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly SalesWeeklyMetricsService $weeklyMetrics,
        private readonly SalesDailyReminderService $dailyReminder,
        private readonly OrganizationScopeService $organizationScope,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', SalesLead::class);
        $user = $request->user();
        $reminderAction = $request->query('reminder_action');
        if ($user->isSales() && in_array($reminderAction, ['lead', 'agenda', 'result'], true)) {
            session()->flash('suppress_sales_reminder_once', true);
            $anchor = match ($reminderAction) {
                'lead' => '#quick-lead-input',
                'agenda' => '#quick-agenda-input',
                default => '',
            };

            return redirect()->to(route('sales-pocketbook.index', $request->except('reminder_action')).$anchor);
        }
        $request->validate(array_merge($this->filterRules(), [
            'sort' => ['nullable', 'in:sales,branch,project,lead_new,contacted,met,surveyed,utj,documents_completed,akad,agenda_completed,last_input'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]));
        $tab = in_array($request->query('tab'), ['leads', 'agenda', 'report'], true) ? $request->query('tab') : 'leads';
        $monitoring = ! $user->isSales();
        $allowedBranchIds = $this->organizationScope->branchIds($user, 'sales_pocketbook');
        $allowedProjectIds = $this->organizationScope->projectIds($user, 'sales_pocketbook');
        $visibleSalesIds = $this->organizationScope->visibleUserIds($user, 'sales_pocketbook');
        $branches = $this->workspaceAccess->accessibleBranches($user)->whereIn('id', $allowedBranchIds)->values();
        $projects = $this->workspaceAccess->accessibleProjects($user)->whereIn('id', $allowedProjectIds)->values();
        $allowedProjectNames = $projects->pluck('project_name')->all();
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
            ->whereIn('id', $visibleSalesIds)
            ->whereHas('role', fn (Builder $query) => $query->where('slug', 'sales'))
            ->when(! $user->canViewAllBranches(), function (Builder $query) use ($user) {
                $branchIds = $this->workspaceAccess->accessibleBranchIds($user);
                $query->whereHas('assignedProjects', fn (Builder $projects) => $projects->whereIn('branch_id', $branchIds));
            })
            ->when($user->isSales(), fn (Builder $query) => $query->whereKey($user->id))
            ->with(['assignedProjects.branch'])->orderBy('name')->get(['id', 'name', 'branch_id']);
        if ($monitoring && $request->filled('sales_user_id') && ! $salesUsers->contains('id', $request->integer('sales_user_id'))) {
            abort(403);
        }
        $selectedSalesId = $monitoring && $request->filled('sales_user_id') ? $request->integer('sales_user_id') : ($user->isSales() ? $user->id : null);
        $this->validateFilterScope($selectedBranchId, $selectedProjectId, $selectedSalesId, $projects, $salesUsers);

        $periodType = $request->query('period_type', 'week');
        $reportPeriod = $this->weeklyMetrics->period(
            $periodType === 'week' ? $request->query('week') : null,
            $periodType === 'custom' ? $request->query('date_from') : null,
            $periodType === 'custom' ? $request->query('date_to') : null,
        );

        $reportMetric = $request->query('report_metric');
        $filterLeadPeriod = $reportMetric || $request->filled('period_type') || $request->filled('week') || ($request->filled('date_from') && $request->filled('date_to'));
        $leads = SalesLead::query()->visibleTo($user)
            ->with(['branch:id,name', 'project:id,project_name', 'sales:id,name', 'leadSource:id,name,is_active'])
            ->withCount('comments')
            ->when($selectedBranchId, fn (Builder $query) => $query->where('branch_id', $selectedBranchId))
            ->when($selectedProjectId, fn (Builder $query) => $query->where('project_id', $selectedProjectId))
            ->when($monitoring && $request->filled('sales_user_id'), fn (Builder $query) => $query->where('sales_user_id', $request->integer('sales_user_id')))
            ->when($request->filled('lead_source_id'), fn (Builder $query) => $query->where('lead_source_id', $request->integer('lead_source_id')))
            ->when($filterLeadPeriod, fn (Builder $query) => $query
                ->whereDate($reportMetric ? SalesWeeklyMetricsService::METRIC_COLUMNS[$reportMetric] : 'lead_date', '>=', $reportPeriod['start']->toDateString())
                ->whereDate($reportMetric ? SalesWeeklyMetricsService::METRIC_COLUMNS[$reportMetric] : 'lead_date', '<=', $reportPeriod['end']->toDateString()))
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

        $agendaDateFrom = $reportPeriod['start']->toDateString();
        $agendaDateTo = $reportPeriod['end']->toDateString();
        $completedAgendaDrilldown = $request->boolean('report_agenda_completed');
        $missingAgendaResultDrilldown = $request->boolean('report_agenda_missing_result');
        $agendas = ContentItem::query()
            ->where('item_type', 'agenda')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->with(['branch:id,name', 'owner:id,name', 'rescheduledFrom:id,scheduled_date'])
            ->withCount('comments')
            ->where(fn (Builder $query) => $query->whereIn('sales_project_id', $allowedProjectIds)
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('sales_project_id')->whereIn('project_name', $allowedProjectNames)))
            ->whereIn('owner_user_id', $visibleSalesIds)
            ->when($user->isSales(), fn (Builder $query) => $query->where('owner_user_id', $user->id))
            ->when(! $user->canViewAllBranches() && ! $user->isSales(), fn (Builder $query) => $query->whereIn('branch_id', $this->workspaceAccess->accessibleBranchIds($user)))
            ->when($selectedBranchId, fn (Builder $query) => $query->where('branch_id', $selectedBranchId))
            ->when($selectedProjectId, fn (Builder $query) => $query->where('sales_project_id', $selectedProjectId))
            ->when($monitoring && $request->filled('sales_user_id'), fn (Builder $query) => $query->where('owner_user_id', $request->integer('sales_user_id')))
            ->when($completedAgendaDrilldown, fn (Builder $query) => $query->where('status', 'done'))
            ->when($missingAgendaResultDrilldown, fn (Builder $query) => $query->where('status', 'done')->whereRaw("TRIM(COALESCE(activity_result, '')) = ''"))
            ->when($agendaDateFrom && ! $missingAgendaResultDrilldown, fn (Builder $query) => $query->whereDate($completedAgendaDrilldown ? 'completed_at' : 'scheduled_date', '>=', $agendaDateFrom))
            ->when($agendaDateTo && ! $missingAgendaResultDrilldown, fn (Builder $query) => $query->whereDate($completedAgendaDrilldown ? 'completed_at' : 'scheduled_date', '<=', $agendaDateTo))
            ->orderBy('scheduled_date')->orderBy('start_time')->paginate(20, ['*'], 'agenda_page')->withQueryString();

        $defaultProject = $this->workspaceAccess->resolveRequestedProject($user, $request->query('project_id'));
        $reportFilters = array_filter([
            'branch_id' => $selectedBranchId,
            'project_id' => $selectedProjectId,
            'sales_user_id' => $monitoring && $request->filled('sales_user_id') ? $request->integer('sales_user_id') : ($user->isSales() ? $user->id : null),
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

        $cascadeProjects = $projects->map(fn ($project) => [
            'id' => (string) $project->id,
            'branch_id' => (string) $project->branch_id,
            'sales_ids' => $project->assignedUsers->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
        ])->values();
        $cascadeSales = $salesUsers->map(fn ($sales) => [
            'id' => (string) $sales->id,
            'project_ids' => $sales->assignedProjects->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
        ])->values();
        $dailyReminder = $this->dailyReminder->state($user);
        if ($user->isSales() && (session('suppress_sales_reminder_once') || session('conflict_data'))) {
            $dailyReminder['shouldShow'] = false;
        }
        $dailyReminder['leadInputUrl'] = route('sales-pocketbook.index', ['input' => 1, 'reminder_action' => 'lead']).'#quick-lead-input';
        $dailyReminder['agendaInputUrl'] = $dailyReminder['hasAssignedProject']
            ? route('sales-pocketbook.index', ['tab' => 'agenda', 'reminder_action' => 'agenda']).'#quick-agenda-input'
            : route('content-calendar.create', ['type' => 'agenda']);
        $dailyReminder['missingResultUrl'] = route('sales-pocketbook.index', [
            'tab' => 'agenda',
            'report_agenda_missing_result' => 1,
            'reminder_action' => 'result',
        ]);
        $dailyReminder['dismissUrl'] = route('sales-reminders.dismiss');

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
            'periodType' => $periodType,
            'cascadeProjects' => $cascadeProjects,
            'cascadeSales' => $cascadeSales,
            'dailyReminder' => $dailyReminder,
        ]);
    }

    public function export(Request $request)
    {
        abort_unless($request->user()->hasPermission('sales_pocketbook.export'), 403);
        $this->authorize('viewAny', SalesLead::class);
        $request->validate($this->filterRules());
        $user = $request->user();
        $allowedBranchIds = $this->organizationScope->branchIds($user, 'sales_pocketbook', 'export');
        $allowedProjectIds = $this->organizationScope->projectIds($user, 'sales_pocketbook', 'export');
        $visibleSalesIds = $this->organizationScope->visibleUserIds($user, 'sales_pocketbook', 'export');
        $branches = $this->workspaceAccess->accessibleBranches($user)->whereIn('id', $allowedBranchIds)->values();
        $projects = $this->workspaceAccess->accessibleProjects($user)->whereIn('id', $allowedProjectIds)->values();
        $projects->load(['branch', 'assignedUsers:id']);

        $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;
        if ($branchId && ! $branches->contains('id', $branchId)) {
            abort(403);
        }

        $projectId = $request->filled('project_id') ? $request->integer('project_id') : null;
        if ($projectId && ! $projects->contains('id', $projectId)) {
            abort(403);
        }

        $salesUsers = User::query()
            ->where('is_active', true)
            ->whereIn('id', $visibleSalesIds)
            ->whereHas('role', fn (Builder $query) => $query->where('slug', 'sales'))
            ->when(! $user->canViewAllBranches(), function (Builder $query) use ($user) {
                $branchIds = $this->workspaceAccess->accessibleBranchIds($user);
                $query->whereHas('assignedProjects', fn (Builder $projects) => $projects->whereIn('branch_id', $branchIds));
            })
            ->when($user->isSales(), fn (Builder $query) => $query->whereKey($user->id))
            ->with(['assignedProjects.branch'])
            ->orderBy('name')
            ->get(['id', 'name', 'branch_id']);

        $requestedSalesId = $request->filled('sales_user_id') ? $request->integer('sales_user_id') : null;
        if ($requestedSalesId && ! $salesUsers->contains('id', $requestedSalesId)) {
            abort(403);
        }
        $salesId = $user->isSales() ? $user->id : $requestedSalesId;
        $this->validateFilterScope($branchId, $projectId, $salesId, $projects, $salesUsers);

        $periodType = $request->query('period_type', 'week');
        $period = $this->weeklyMetrics->period(
            $periodType === 'week' ? $request->query('week') : null,
            $periodType === 'custom' ? $request->query('date_from') : null,
            $periodType === 'custom' ? $request->query('date_to') : null,
        );
        $filters = array_filter([
            'branch_id' => $branchId,
            'project_id' => $projectId,
            'sales_user_id' => $salesId,
        ]);
        $reportMetric = $request->query('report_metric');
        $leadDateColumn = $reportMetric ? SalesWeeklyMetricsService::METRIC_COLUMNS[$reportMetric] : 'lead_date';
        $completedAgendaDrilldown = $request->boolean('report_agenda_completed');
        $missingAgendaResultDrilldown = $request->boolean('report_agenda_missing_result');

        $leads = $this->weeklyMetrics->leadQuery($user, $filters)
            ->with(['branch:id,name', 'project:id,project_name', 'sales:id,name'])
            ->whereDate($leadDateColumn, '>=', $period['start']->toDateString())
            ->whereDate($leadDateColumn, '<=', $period['end']->toDateString())
            ->when($request->filled('lead_source_id'), fn (Builder $query) => $query->where('lead_source_id', $request->integer('lead_source_id')))
            ->when($request->filled('stage'), function (Builder $query) use ($request) {
                $stage = (string) $request->query('stage');
                $query->whereNotNull($stage);
                $laterStages = array_slice(SalesLead::STAGE_ORDER, array_search($stage, SalesLead::STAGE_ORDER, true) + 1);
                foreach ($laterStages as $laterStage) {
                    $query->whereNull($laterStage);
                }
            })
            ->orderBy('lead_date')
            ->orderBy('id')
            ->get();

        $agendas = $this->weeklyMetrics->agendaQuery($user, $filters)
            ->with(['branch:id,name', 'owner:id,name', 'salesProject:id,project_name', 'rescheduledFrom:id,scheduled_date'])
            ->when($completedAgendaDrilldown, fn (Builder $query) => $query->where('status', 'done'))
            ->when($missingAgendaResultDrilldown, fn (Builder $query) => $query->where('status', 'done')->whereRaw("TRIM(COALESCE(activity_result, '')) = ''"))
            ->when(! $missingAgendaResultDrilldown, fn (Builder $query) => $query
                ->whereDate($completedAgendaDrilldown ? 'completed_at' : 'scheduled_date', '>=', $period['start']->toDateString())
                ->whereDate($completedAgendaDrilldown ? 'completed_at' : 'scheduled_date', '<=', $period['end']->toDateString()))
            ->orderBy('scheduled_date')
            ->orderBy('start_time')
            ->get();

        $weeklyRows = $this->weeklyMetrics->monitoringRows($user, $period, $salesUsers, $projects, $filters);
        if ($leads->isEmpty() && $agendas->isEmpty()) {
            return back()->with('warning', 'Tidak ada data Buku Saku Sales pada filter dan periode yang dipilih.');
        }

        $branchName = $branchId ? $branches->firstWhere('id', $branchId)?->name : 'semua-cabang';
        $selectedSales = $salesId ? $salesUsers->firstWhere('id', $salesId) : null;
        $salesName = $selectedSales?->name ?? 'semua-sales';
        $filename = sprintf(
            'buku-saku-sales_%s_%s_%s_%s.xlsx',
            str($branchName)->slug()->value() ?: 'cabang',
            str($salesName)->slug()->value() ?: 'sales',
            $period['start']->toDateString(),
            $period['end']->toDateString(),
        );

        return SalesPocketbookExport::toBrowser($weeklyRows, $leads, $agendas, $period, $filename);
    }

    private function filterRules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'sales_user_id' => ['nullable', 'integer'],
            'lead_source_id' => ['nullable', 'integer', Rule::exists('lead_sources', 'id')->where('is_active', true)],
            'stage' => ['nullable', Rule::in(array_keys(SalesLead::STAGES))],
            'period_type' => ['nullable', 'required_with:week,date_from,date_to', Rule::in(['week', 'custom'])],
            'week' => ['nullable', 'date', 'required_if:period_type,week', 'prohibited_if:period_type,custom'],
            'date_from' => ['nullable', 'date', 'required_if:period_type,custom', 'prohibited_if:period_type,week'],
            'date_to' => ['nullable', 'date', 'required_if:period_type,custom', 'prohibited_if:period_type,week', 'after_or_equal:date_from'],
            'report_metric' => ['nullable', Rule::in(array_keys(SalesWeeklyMetricsService::METRIC_COLUMNS))],
            'report_agenda_completed' => ['nullable', 'boolean'],
            'report_agenda_missing_result' => ['nullable', 'boolean'],
        ];
    }

    private function validateFilterScope(?int $branchId, ?int $projectId, ?int $salesId, $projects, $salesUsers): void
    {
        $project = $projectId ? $projects->firstWhere('id', $projectId) : null;
        $sales = $salesId ? $salesUsers->firstWhere('id', $salesId) : null;

        if ($project && $branchId && (int) $project->branch_id !== $branchId) {
            throw ValidationException::withMessages(['project_id' => 'Proyek harus berada di cabang yang dipilih.']);
        }
        if ($project && $sales && ! $project->assignedUsers->contains('id', $sales->id)) {
            throw ValidationException::withMessages(['sales_user_id' => 'Sales harus ditugaskan ke proyek yang dipilih.']);
        }
        if ($branchId && $sales && ! $sales->assignedProjects->contains(fn ($assigned) => (int) $assigned->branch_id === $branchId)) {
            throw ValidationException::withMessages(['sales_user_id' => 'Sales harus ditugaskan ke proyek pada cabang yang dipilih.']);
        }
    }
}
