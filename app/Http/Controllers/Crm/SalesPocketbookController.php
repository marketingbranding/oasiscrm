<?php

namespace App\Http\Controllers\Crm;

use App\Exports\SalesPocketbookExport;
use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\LeadSource;
use App\Models\SalesLead;
use App\Models\SalesLeadLifecycleReconciliationItem;
use App\Models\SalesLeadLifecycleSyncStatus;
use App\Models\User;
use App\Services\OrganizationScopeService;
use App\Services\SalesDailyReminderService;
use App\Services\SalesLeadSheetOptionService;
use App\Services\SalesLeadSyncService;
use App\Services\SalesTeamScopeService;
use App\Services\SalesWeeklyMetricsService;
use App\Services\WorkspaceAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class SalesPocketbookController extends Controller
{
    public function __construct(
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly SalesWeeklyMetricsService $weeklyMetrics,
        private readonly SalesDailyReminderService $dailyReminder,
        private readonly OrganizationScopeService $organizationScope,
        private readonly SalesTeamScopeService $salesTeamScope,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->isSales() && ! $request->boolean('input')) {
            return app(SalesAgendaController::class)->index($request);
        }

        if ($user->hasPrimaryRole('sales_coordinator')) {
            return app(CoordinatorSalesLeadWorkspaceController::class)->index($request);
        }

        if ($user->hasPrimaryRole('supervisor')) {
            return app(SupervisorSalesPocketbookController::class)->index($request);
        }

        if ($user->hasPrimaryRole('admin')) {
            return app(AdminSalesPocketbookController::class)->index($request);
        }

        $this->authorize('viewAny', SalesLead::class);
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
        $allowedBranchIds = array_values(array_intersect(
            $this->organizationScope->branchIds($user, 'sales_pocketbook'),
            $this->workspaceAccess->accessibleBranchIds($user),
        ));
        $allowedProjectIds = array_values(array_intersect(
            $this->organizationScope->projectIds($user, 'sales_pocketbook'),
            $this->workspaceAccess->accessibleProjectIds($user),
        ));
        $pureManagerHierarchy = $user->hasPrimaryRole(['manager', 'branch_manager']) ? $this->salesTeamScope->for($user) : null;
        if ($pureManagerHierarchy) {
            $today = today()->toDateString();
            $allowedProjectIds = LeadMaster::query()
                ->whereIn('id', $allowedProjectIds)->whereIn('branch_id', $allowedBranchIds)->where('is_active', true)
                ->whereHas('assignedUsers', fn (Builder $query) => $query
                    ->whereIn('users.id', $pureManagerHierarchy['sales']->pluck('id'))
                    ->where('project_user.is_active', true)
                    ->where(fn (Builder $dates) => $dates->whereNull('project_user.assignment_start_date')->orWhereDate('project_user.assignment_start_date', '<=', $today))
                    ->where(fn (Builder $dates) => $dates->whereNull('project_user.assignment_end_date')->orWhereDate('project_user.assignment_end_date', '>=', $today)))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        $managerHierarchy = $pureManagerHierarchy
            ? $this->salesTeamScope->displayedFor($user, $allowedBranchIds, $allowedProjectIds)
            : null;
        $visibleSalesIds = $managerHierarchy
            ? $managerHierarchy['sales']->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $this->organizationScope->visibleUserIds($user, 'sales_pocketbook');
        $canonicalScope = $managerHierarchy ? ['owner_ids' => $visibleSalesIds, 'branch_ids' => $allowedBranchIds, 'project_ids' => $allowedProjectIds] : null;
        $branches = $this->workspaceAccess->accessibleBranches($user)->whereIn('id', $allowedBranchIds)->values();
        $projects = $this->workspaceAccess->accessibleProjects($user)->whereIn('id', $allowedProjectIds)->where('is_active', true)->values();
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
        $leadSourceFilter = $this->leadSourceFilter($request);

        $periodType = $request->query('period_type', 'week');
        $reportPeriod = $this->weeklyMetrics->period(
            $periodType === 'week' ? $request->query('week') : null,
            $periodType === 'custom' ? $request->query('date_from') : null,
            $periodType === 'custom' ? $request->query('date_to') : null,
        );

        $reportMetric = $request->query('report_metric');
        $filterLeadPeriod = $reportMetric || $request->filled('period_type') || $request->filled('week') || ($request->filled('date_from') && $request->filled('date_to'));
        $leads = ($canonicalScope
            ? $this->weeklyMetrics->canonicalLeadQuery($canonicalScope)
            : SalesLead::query()->visibleTo($user)->whereIn('sales_user_id', $visibleSalesIds))
            ->with([
                'branch:id,name,sheet_id', 'project:id,project_name,is_nup_eligible', 'sales:id,name,supervisor_user_id',
                'leadSource:id,name,is_active', 'siteVisits' => fn ($query) => $query->latest('id'),
                'consumerLinks' => fn ($query) => $query->latest('id'), 'slikAttempts' => fn ($query) => $query->latest('id'),
                'freelanceLinks' => fn ($query) => $query->latest('id'), 'akadLinks' => fn ($query) => $query->latest('id'),
            ])
            ->withCount('comments')
            ->when($selectedBranchId, fn (Builder $query) => $query->where('branch_id', $selectedBranchId))
            ->when($selectedProjectId, fn (Builder $query) => $query->where('project_id', $selectedProjectId))
            ->when($monitoring && $request->filled('sales_user_id'), fn (Builder $query) => $query->where('sales_user_id', $request->integer('sales_user_id')))
            ->when($leadSourceFilter, fn (Builder $query) => $query->whereEffectiveSource($leadSourceFilter))
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
        $agendas = ($canonicalScope
            ? $this->weeklyMetrics->canonicalAgendaQuery($canonicalScope)
            : ContentItem::query()->where('item_type', 'agenda')->where('agenda_type', ContentItem::SALES_AGENDA_TYPE))
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
        $reportSummary = $this->weeklyMetrics->metrics($user, $reportPeriod, $reportFilters, $canonicalScope);
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

        $syncBranch = ($selectedBranchId ? $branches->firstWhere('id', $selectedBranchId) : null)
            ?? ($user->isSales() ? $branches->firstWhere('id', $defaultProject?->branch_id ?? $user->branch_id) : null);
        $manageBranchIds = $this->organizationScope->branchIds($user, 'sales_pocketbook', 'manage');
        $canLifecycleSync = config('services.google_sheets.sales_lead_sync_enabled')
            && $syncBranch !== null
            && $user->hasPermission('sales_pocketbook.sync')
            && in_array((int) $syncBranch->id, $manageBranchIds, true)
            && $this->workspaceAccess->canViewBranch($user, $syncBranch)
            && ($user->isSales()
                ? $this->workspaceAccess->accessibleProjectsQuery($user)->where('branch_id', $syncBranch->id)->exists()
                : $this->workspaceAccess->canSyncBranch($user, $syncBranch));
        $canReconcile = $syncBranch !== null
            && $user->hasPermission('sales_pocketbook.reconcile')
            && in_array((int) $syncBranch->id, $manageBranchIds, true)
            && $this->workspaceAccess->canViewBranch($user, $syncBranch)
            && $this->workspaceAccess->canSyncBranch($user, $syncBranch);
        $lifecycleSyncStatus = $syncBranch
            ? SalesLeadLifecycleSyncStatus::query()->where('branch_id', $syncBranch->id)->where('scope', SalesLeadSyncService::scopeFor($user))->first()
            : null;
        $reconciliationCount = $canReconcile
            ? SalesLeadLifecycleReconciliationItem::query()->where('branch_id', $syncBranch->id)->where('status', 'open')->whereIn('entity_type', ['lead', 'lead_status'])->count()
            : 0;
        $lifecycleCapabilitiesByBranch = [];
        foreach (SalesLeadLifecycleSyncStatus::query()
            ->whereIn('branch_id', $branches->pluck('id'))
            ->where('scope', SalesLeadSyncService::branchScope())
            ->whereIn('status', ['success', 'partial_success'])
            ->get() as $status) {
            $lifecycleCapabilitiesByBranch[$status->branch_id] = $status->summary['capabilities'] ?? [];
        }
        foreach (SalesLeadLifecycleSyncStatus::query()
            ->whereIn('branch_id', $branches->pluck('id'))
            ->where('scope', 'like', SalesLeadSyncService::SCOPE_USER_PREFIX.'%')
            ->whereIn('status', ['success', 'partial_success'])
            ->get() as $status) {
            $lifecycleCapabilitiesByBranch[$status->branch_id] ??= $status->summary['capabilities'] ?? [];
        }
        $coordinators = User::query()->where('is_active', true)->whereIn('id', $visibleSalesIds)
            ->whereHas('role', fn (Builder $query) => $query->whereIn('slug', ['sales_coordinator', 'supervisor', 'manager', 'branch_manager']))
            ->orderBy('name')->get(['id', 'name', 'branch_id']);
        $sourceOptionQuery = SalesLead::query()->visibleTo($user)
            ->whereIn('sales_user_id', $visibleSalesIds)
            ->when($selectedBranchId, fn (Builder $query) => $query->where('branch_id', $selectedBranchId))
            ->when($selectedProjectId, fn (Builder $query) => $query->where('project_id', $selectedProjectId))
            ->when($selectedSalesId, fn (Builder $query) => $query->where('sales_user_id', $selectedSalesId));
        $leadSourceOptions = (clone $sourceOptionQuery)
            ->whereRaw("TRIM(COALESCE(source, '')) <> ''")
            ->distinct()
            ->pluck('source')
            ->merge((clone $sourceOptionQuery)
                ->whereRaw("TRIM(COALESCE(source, '')) = ''")
                ->whereRaw("TRIM(COALESCE(source_name_snapshot, '')) <> ''")
                ->distinct()
                ->pluck('source_name_snapshot'))
            ->merge((clone $sourceOptionQuery)
                ->whereRaw("TRIM(COALESCE(sales_leads.source, '')) = ''")
                ->whereRaw("TRIM(COALESCE(sales_leads.source_name_snapshot, '')) = ''")
                ->join('lead_sources', 'lead_sources.id', '=', 'sales_leads.lead_source_id')
                ->distinct()
                ->pluck('lead_sources.name'))
            ->filter()->unique()->values();
        if (config('services.google_sheets.sales_lead_sync_enabled') && $selectedBranchId) {
            try {
                $workbookSources = app(SalesLeadSheetOptionService::class)->forBranch($branches->firstWhere('id', $selectedBranchId))['source'];
                $leadSourceOptions = $leadSourceOptions->merge($workbookSources)->filter()->unique()->sort()->values();
            } catch (Throwable) {
                // Historical values remain available when the workbook is temporarily unavailable.
            }
        }

        return view('crm.sales-pocketbook.index', [
            'tab' => $tab,
            'monitoring' => $monitoring,
            'branches' => $branches,
            'projects' => $projects,
            'salesUsers' => $salesUsers,
            'leadSourceOptions' => $leadSourceOptions,
            'leadSourceFilter' => $leadSourceFilter,
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
            'syncBranch' => $syncBranch,
            'canLifecycleSync' => $canLifecycleSync,
            'canReconcile' => $canReconcile,
            'lifecycleSyncStatus' => $lifecycleSyncStatus,
            'reconciliationCount' => $reconciliationCount,
            'lifecycleCapabilitiesByBranch' => $lifecycleCapabilitiesByBranch,
            'coordinators' => $coordinators,
            'managerHierarchy' => $managerHierarchy,
        ]);
    }

    public function export(Request $request)
    {
        abort_if($request->user()->hasPrimaryRole(['sales', 'sales_coordinator', 'supervisor']), 403);
        abort_unless($request->user()->hasPermission('sales_pocketbook.export'), 403);
        $this->authorize('viewAny', SalesLead::class);
        $request->validate($this->filterRules());
        $user = $request->user();
        $allowedBranchIds = $this->organizationScope->branchIds($user, 'sales_pocketbook', 'export');
        $allowedProjectIds = $this->organizationScope->projectIds($user, 'sales_pocketbook', 'export');
        $pureManagerHierarchy = $user->hasPrimaryRole(['manager', 'branch_manager']) ? $this->salesTeamScope->for($user) : null;
        if ($pureManagerHierarchy) {
            $today = today()->toDateString();
            $allowedProjectIds = LeadMaster::query()
                ->whereIn('id', $allowedProjectIds)->whereIn('branch_id', $allowedBranchIds)->where('is_active', true)
                ->whereHas('assignedUsers', fn (Builder $query) => $query
                    ->whereIn('users.id', $pureManagerHierarchy['sales']->pluck('id'))
                    ->where('project_user.is_active', true)
                    ->where(fn (Builder $dates) => $dates->whereNull('project_user.assignment_start_date')->orWhereDate('project_user.assignment_start_date', '<=', $today))
                    ->where(fn (Builder $dates) => $dates->whereNull('project_user.assignment_end_date')->orWhereDate('project_user.assignment_end_date', '>=', $today)))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        $managerHierarchy = $pureManagerHierarchy
            ? $this->salesTeamScope->displayedFor($user, $allowedBranchIds, $allowedProjectIds)
            : null;
        $visibleSalesIds = $managerHierarchy
            ? $managerHierarchy['sales']->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $this->organizationScope->visibleUserIds($user, 'sales_pocketbook', 'export');
        $canonicalScope = $managerHierarchy ? ['owner_ids' => $visibleSalesIds, 'branch_ids' => $allowedBranchIds, 'project_ids' => $allowedProjectIds] : null;
        $branches = $this->workspaceAccess->accessibleBranches($user)->whereIn('id', $allowedBranchIds)->values();
        $projects = $this->workspaceAccess->accessibleProjects($user)->whereIn('id', $allowedProjectIds)->where('is_active', true)->values();
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
        $leadSourceFilter = $this->leadSourceFilter($request);

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

        $leads = ($canonicalScope
            ? $this->weeklyMetrics->canonicalLeadQuery($canonicalScope, $filters)
            : $this->weeklyMetrics->leadQuery($user, $filters)->whereIn('sales_user_id', $visibleSalesIds))
            ->with(['branch:id,name', 'project:id,project_name', 'sales:id,name', 'leadSource:id,name'])
            ->whereDate($leadDateColumn, '>=', $period['start']->toDateString())
            ->whereDate($leadDateColumn, '<=', $period['end']->toDateString())
            ->when($leadSourceFilter, fn (Builder $query) => $query->whereEffectiveSource($leadSourceFilter))
            ->when($request->filled('stage'), function (Builder $query) use ($request) {
                $stage = (string) $request->query('stage');
                abort_unless(array_key_exists($stage, SalesLead::STAGES), 422);
                $query->whereNotNull($stage);
                $laterStages = array_slice(SalesLead::STAGE_ORDER, array_search($stage, SalesLead::STAGE_ORDER, true) + 1);
                foreach ($laterStages as $laterStage) {
                    $query->whereNull($laterStage);
                }
            })
            ->orderBy('lead_date')
            ->orderBy('id')
            ->get();

        $agendas = ($canonicalScope
            ? $this->weeklyMetrics->canonicalAgendaQuery($canonicalScope, $filters)
            : $this->weeklyMetrics->agendaQuery($user, $filters)->whereIn('owner_user_id', $visibleSalesIds))
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

    private function managerMetrics(User $user, array $period, array $filters, array $salesIds): array
    {
        $leadQuery = $this->weeklyMetrics->leadQuery($user, $filters)->whereIn('sales_user_id', $salesIds);
        $metrics = [];
        foreach (SalesWeeklyMetricsService::METRIC_COLUMNS as $metric => $column) {
            $metrics[$metric] = $column === 'lead_date'
                ? (clone $leadQuery)->whereDate($column, '>=', $period['start']->toDateString())->whereDate($column, '<=', $period['end']->toDateString())->count()
                : (clone $leadQuery)->whereBetween($column, [$period['start'], $period['end']])->count();
        }
        $agendaQuery = $this->weeklyMetrics->agendaQuery($user, $filters)->whereIn('owner_user_id', $salesIds);
        $metrics['agenda_completed'] = (clone $agendaQuery)->where('status', 'done')->whereBetween('completed_at', [$period['start'], $period['end']])->count();
        $metrics['conversions'] = [
            'lead_contacted' => $metrics['lead_new'] ? round($metrics['contacted'] / $metrics['lead_new'] * 100, 1) : null,
            'contacted_met' => $metrics['contacted'] ? round($metrics['met'] / $metrics['contacted'] * 100, 1) : null,
            'met_survey' => $metrics['met'] ? round($metrics['surveyed'] / $metrics['met'] * 100, 1) : null,
            'survey_utj' => $metrics['surveyed'] ? round($metrics['utj'] / $metrics['surveyed'] * 100, 1) : null,
            'utj_documents' => $metrics['utj'] ? round($metrics['documents_completed'] / $metrics['utj'] * 100, 1) : null,
            'documents_akad' => $metrics['documents_completed'] ? round($metrics['akad'] / $metrics['documents_completed'] * 100, 1) : null,
        ];
        $lastInput = collect([(clone $leadQuery)->max('created_at'), (clone $agendaQuery)->max('created_at')])->filter()->max();
        $metrics['last_input'] = $lastInput ? CarbonImmutable::parse($lastInput, config('app.timezone')) : null;

        return $metrics;
    }

    private function filterRules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'sales_user_id' => ['nullable', 'integer'],
            'lead_source' => ['nullable', 'string', 'max:255'],
            'lead_source_id' => ['nullable', 'integer', 'exists:lead_sources,id'],
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

    private function leadSourceFilter(Request $request): ?string
    {
        if ($request->filled('lead_source')) {
            return trim($request->string('lead_source')->toString());
        }

        return $request->filled('lead_source_id')
            ? LeadSource::query()->whereKey($request->integer('lead_source_id'))->value('name')
            : null;
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
