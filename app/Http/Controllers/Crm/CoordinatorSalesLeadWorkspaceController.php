<?php

namespace App\Http\Controllers\Crm;

use App\Enums\SalesLeadStatus;
use App\Exports\CoordinatorSalesLeadExport;
use App\Http\Controllers\Controller;
use App\Models\LeadMaster;
use App\Models\SalesDailyPlan;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\CoordinatorLeadPushService;
use App\Services\CoordinatorLeadTeamService;
use App\Services\CoordinatorSalesMonitoringService;
use App\Services\PromoOptionService;
use App\Services\SalesLeadTargetService;
use App\Services\WorkspaceAccessService;
use App\Support\SalesLeadMasterData;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CoordinatorSalesLeadWorkspaceController extends Controller
{
    public function __construct(
        private readonly CoordinatorLeadTeamService $teams,
        private readonly CoordinatorSalesLeadExport $export,
        private readonly CoordinatorSalesMonitoringService $monitoring,
        private readonly PromoOptionService $promoOptions,
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly SalesLeadTargetService $leadTargets,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'tab' => ['nullable', 'in:lead,agenda,report'],
            'period' => ['nullable', 'in:today,week,month,custom'],
            'date_from' => ['nullable', 'required_if:period,custom', 'date'],
            'date_to' => ['nullable', 'required_if:period,custom', 'date', 'after_or_equal:date_from'],
            'sales_id' => ['nullable', 'integer'],
            'target_month' => ['nullable', 'date_format:Y-m'],
        ]);
        $tab = $filters['tab'] ?? 'lead';
        unset($filters['tab']);

        $data = $this->monitoring->resolve($request->user(), $filters);
        $targetMonthValue = $request->query('target_month', now(config('app.timezone'))->format('Y-m'));
        $targetMonth = CarbonImmutable::createFromFormat('Y-m-d', $targetMonthValue.'-01', config('app.timezone'));
        $data['targetMonth'] = $targetMonth;
        $data['targetSummaries'] = $data['salesUsers']->mapWithKeys(fn (User $sales) => [$sales->id => $this->leadTargets->summary($sales, $targetMonth)]);
        $dailyPlanDate = CarbonImmutable::createFromFormat('Y-m-d', $request->query('work_date', $data['period']['from']->toDateString()), config('app.timezone'));
        $data['dailyPlanDate'] = $dailyPlanDate;
        $data['dailyPlans'] = SalesDailyPlan::query()
            ->with(['sales:id,name', 'items'])
            ->whereIn('sales_user_id', $data['salesUsers']->pluck('id'))
            ->whereDate('work_date', $dailyPlanDate->toDateString())
            ->orderBy('sales_user_id')
            ->get();
        $data['projectsBySales'] = $data['salesUsers']->mapWithKeys(fn ($sales) => [
            (string) $sales->id => $sales->assignedProjects->map(fn ($project) => [
                'id' => (string) $project->id,
                'name' => $project->project_name,
                'branch_id' => (string) $project->branch_id,
                'branch_name' => $project->branch?->name,
            ])->values(),
        ]);
        $data['sources'] = SalesLeadMasterData::SOURCES;
        $data['channels'] = SalesLeadMasterData::CHANNELS;
        $data['activities'] = SalesLeadMasterData::ACTIVITIES;
        $initialProject = LeadMaster::find($request->old('project_id'));
        $data['promos'] = $initialProject
            ? $this->promoOptions->availableForBranchAndDate((int) $initialProject->branch_id, $request->old('lead_date', today()))
            : collect([PromoOptionService::NO_PROMO]);
        $data['promoOptionsEndpoint'] = route('coordinator-leads.promo-options', ['project' => 'PROJECT_ID']);
        $data['statuses'] = SalesLeadStatus::MANUAL;
        $data['canCreate'] = $request->user()->can('create', SalesLead::class);
        $data['canManageAgenda'] = $request->user()->hasPermission('sales_pocketbook.manage_team');
        $data['canExport'] = $request->user()->hasPermission('sales_pocketbook.export_team');
        $data['canSync'] = config('oasis.spreadsheet_sync_enabled')
            && config('services.google_sheets.sales_lead_sync_enabled')
            && $request->user()->hasPermission('sales_pocketbook.sync');
        $data['tab'] = $tab;

        return view('crm.sales-pocketbook.coordinator-leads', $data);
    }

    public function promoOptions(Request $request, LeadMaster $project): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->teams->isCoordinator($user), 403);
        abort_unless($project->is_active && $this->workspaceAccess->canAccessProject($user, $project), 403);
        abort_unless($this->teams->currentSalesQuery($user)->whereHas('assignedProjects', fn ($query) => $query->whereKey($project->id))->exists(), 403);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'lead_id' => ['nullable', 'integer'],
        ]);
        $lead = isset($data['lead_id']) ? SalesLead::findOrFail($data['lead_id']) : null;
        if ($lead) {
            abort_unless((int) $lead->project_id === (int) $project->id && (int) $lead->branch_id === (int) $project->branch_id, 403);
            $this->authorize('update', $lead);
        }

        return response()->json([
            'options' => $this->promoOptions->availableForBranchAndDate(
                (int) $project->branch_id,
                $data['date'],
                $lead?->id_promo,
            ),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($this->teams->isCoordinator($user), 403);
        abort_unless($user->hasPermission('sales_pocketbook.export_team'), 403);

        $filters = $request->validate([
            'period' => ['nullable', 'in:today,week,month,custom'],
            'date_from' => ['nullable', 'required_if:period,custom', 'date'],
            'date_to' => ['nullable', 'required_if:period,custom', 'date', 'after_or_equal:date_from'],
            'sales_id' => ['nullable', 'integer'],
        ]);
        $data = $this->monitoring->resolve($user, $filters, false);

        return $this->export->toBrowser($data['exportData'], 'lead-tim-sales-'.now()->format('Ymd-His').'.xlsx');
    }

    public function push(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->teams->isCoordinator($user), 403);
        abort_unless($user->hasPermission('sales_pocketbook.sync'), 403);

        if (! config('services.google_sheets.sales_lead_sync_enabled')) {
            return back()->with('error', 'Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.')->setStatusCode(503);
        }

        $result = app(CoordinatorLeadPushService::class)->push($user);
        $message = "{$result['synced']} lead tersinkron";

        return back()->with($result['failed'] > 0 ? 'warning' : 'success', $result['failed'] > 0
            ? $message.", {$result['failed']} gagal."
            : $message.'.');
    }
}
