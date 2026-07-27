<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\LeadSource;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\WorkspaceAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class SalesPocketbookController extends Controller
{
    public function __construct(private readonly WorkspaceAccessService $workspaceAccess) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', SalesLead::class);
        $user = $request->user();
        $tab = in_array($request->query('tab'), ['leads', 'agenda', 'report'], true) ? $request->query('tab') : 'leads';
        $monitoring = ! $user->hasRole('sales');
        $branches = $this->workspaceAccess->accessibleBranches($user);
        $projects = $this->workspaceAccess->accessibleProjects($user);
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
            ->when(! $user->canViewAllBranches(), fn (Builder $query) => $query->whereIn('branch_id', $this->workspaceAccess->accessibleBranchIds($user)))
            ->when($user->hasRole('sales'), fn (Builder $query) => $query->whereKey($user->id))
            ->orderBy('name')->get(['id', 'name', 'branch_id']);
        if ($monitoring && $request->filled('sales_user_id') && ! $salesUsers->contains('id', $request->integer('sales_user_id'))) {
            abort(403);
        }

        $leads = SalesLead::query()->visibleTo($user)
            ->with(['branch:id,name', 'project:id,project_name', 'sales:id,name', 'leadSource:id,name'])
            ->when($selectedBranchId, fn (Builder $query) => $query->where('branch_id', $selectedBranchId))
            ->when($selectedProjectId, fn (Builder $query) => $query->where('project_id', $selectedProjectId))
            ->when($monitoring && $request->filled('sales_user_id'), fn (Builder $query) => $query->where('sales_user_id', $request->integer('sales_user_id')))
            ->when($request->filled('lead_source_id'), fn (Builder $query) => $query->where('lead_source_id', $request->integer('lead_source_id')))
            ->when($request->filled('date_from'), fn (Builder $query) => $query->whereDate('lead_date', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn (Builder $query) => $query->whereDate('lead_date', '<=', $request->query('date_to')))
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

        $defaultProject = $this->workspaceAccess->resolveRequestedProject($user, $request->query('project_id'));

        return view('crm.sales-pocketbook.index', [
            'tab' => $tab,
            'monitoring' => $monitoring,
            'branches' => $branches,
            'projects' => $projects,
            'salesUsers' => $salesUsers,
            'leadSources' => LeadSource::where('is_active', true)->orderBy('name')->get(),
            'leads' => $leads,
            'defaultProject' => $defaultProject,
            'selectedBranchId' => $selectedBranchId,
            'selectedProjectId' => $selectedProjectId,
            'canCreate' => $user->can('create', SalesLead::class),
        ]);
    }
}
