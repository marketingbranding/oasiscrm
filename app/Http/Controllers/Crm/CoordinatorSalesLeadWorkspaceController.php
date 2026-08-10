<?php

namespace App\Http\Controllers\Crm;

use App\Exports\CoordinatorSalesLeadExport;
use App\Http\Controllers\Controller;
use App\Models\SalesLead;
use App\Services\CoordinatorLeadPushService;
use App\Services\CoordinatorLeadTeamService;
use App\Services\SalesLeadService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CoordinatorSalesLeadWorkspaceController extends Controller
{
    public function __construct(
        private readonly CoordinatorLeadTeamService $teams,
        private readonly CoordinatorLeadPushService $pushService,
        private readonly CoordinatorSalesLeadExport $export,
        private readonly SalesLeadService $leads,
        private readonly WorkspaceAccessService $workspaceAccess,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($this->teams->isCoordinator($user), 403);

        $salesUsers = $this->teams->currentSales($user)->sortBy('name')->values();
        $accessibleProjectIds = $this->workspaceAccess->accessibleProjectIds($user);
        $salesUsers->load(['assignedProjects' => fn ($query) => $query
            ->where('lead_master.is_active', true)
            ->whereIn('lead_master.id', $accessibleProjectIds)
            ->wherePivot('is_active', true)
            ->where(fn ($query) => $query->whereNull('project_user.assignment_start_date')->orWhereDate('project_user.assignment_start_date', '<=', today()))
            ->where(fn ($query) => $query->whereNull('project_user.assignment_end_date')->orWhereDate('project_user.assignment_end_date', '>=', today()))
            ->with('branch:id,name')
            ->orderBy('project_name'),
        ]);

        $salesIds = $salesUsers->pluck('id');
        $leads = SalesLead::query()
            ->whereIn('sales_user_id', $salesIds)
            ->with(['branch:id,name', 'project:id,project_name', 'sales:id,name'])
            ->latest('lead_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();
        $syncCounters = SalesLead::query()
            ->whereIn('sales_user_id', $salesIds)
            ->selectRaw("SUM(CASE WHEN sync_status = 'pending_create' THEN 1 ELSE 0 END) AS pending_create")
            ->selectRaw("SUM(CASE WHEN sync_status = 'synced' THEN 1 ELSE 0 END) AS synced")
            ->selectRaw("SUM(CASE WHEN sync_status = 'pending_update' THEN 1 ELSE 0 END) AS pending_update")
            ->selectRaw("SUM(CASE WHEN sync_status = 'sync_failed' THEN 1 ELSE 0 END) AS sync_failed")
            ->first();
        $projectsBySales = $salesUsers->mapWithKeys(fn ($sales) => [
            (string) $sales->id => $sales->assignedProjects->map(fn ($project) => [
                'id' => (string) $project->id,
                'name' => $project->project_name,
                'branch_id' => (string) $project->branch_id,
                'branch_name' => $project->branch?->name,
            ])->values(),
        ]);

        return view('crm.sales-pocketbook.coordinator-leads', [
            'salesUsers' => $salesUsers,
            'projectsBySales' => $projectsBySales,
            'leads' => $leads,
            'syncCounters' => $syncCounters,
            'canSync' => $user->hasPermission('sales_pocketbook.sync'),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($this->teams->isCoordinator($user), 403);
        abort_unless($user->hasPermission('sales_pocketbook.export'), 403);

        $leads = SalesLead::query()
            ->whereIn('sales_user_id', $this->teams->currentSales($user)->pluck('id'))
            ->with(['branch:id,name', 'project:id,project_name', 'sales:id,name', 'leadSource:id,name'])
            ->orderBy('lead_date')
            ->orderBy('id')
            ->get();

        return $this->export->toBrowser($leads, 'lead-tim-sales-'.now()->format('Ymd-His').'.xlsx');
    }

    public function push(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->teams->isCoordinator($user), 403);
        abort_unless($user->hasPermission('sales_pocketbook.sync'), 403);

        $result = $this->pushService->push($user);
        $message = "{$result['synced']} lead tersinkron";

        return back()->with($result['failed'] > 0 ? 'warning' : 'success', $result['failed'] > 0
            ? $message.", {$result['failed']} gagal."
            : $message.'.');
    }
}
