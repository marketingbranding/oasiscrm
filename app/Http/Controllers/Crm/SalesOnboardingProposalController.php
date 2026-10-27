<?php

namespace App\Http\Controllers\Crm;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SalesOnboardingDecisionRequest;
use App\Http\Requests\SalesOnboardingProposalRequest;
use App\Models\Role;
use App\Models\SalesOnboardingProposal;
use App\Services\SalesOnboardingProposalService;
use App\Services\UserAdministrationService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesOnboardingProposalController extends Controller
{
    public function __construct(
        private readonly SalesOnboardingProposalService $proposals,
        private readonly WorkspaceAccessService $workspace,
        private readonly UserAdministrationService $administration,
    ) {}

    public function index(): View
    {
        $proposals = SalesOnboardingProposal::query()
            ->with(['branch', 'project', 'coordinator', 'submitter', 'decider'])
            ->latest('id')
            ->paginate(20);

        return view('crm.admin-users.sales-proposals', compact('proposals'));
    }

    public function create(Request $request): View
    {
        $actor = $request->user();
        $branches = $this->workspace->accessibleBranches($actor);
        $projects = $this->workspace->accessibleProjects($actor);
        $coordinators = $this->administration->visibleQuery($actor)
            ->where('account_status', AccountStatus::Active->value)
            ->whereHas('role', fn ($query) => $query->where('slug', 'sales_coordinator'))
            ->with('role')
            ->orderBy('name')
            ->get();
        $salesRole = Role::query()->where('slug', 'sales')->where('is_active', true)->value('id');

        return view('crm.admin-users.sales-proposal-create', compact('branches', 'projects', 'coordinators', 'salesRole'));
    }

    public function store(SalesOnboardingProposalRequest $request): RedirectResponse
    {
        $this->proposals->create($request->validated(), $request->user());

        return redirect()->route($request->user()->isSuperadmin() ? 'admin-users.sales-proposals.index' : 'admin-users.index')
            ->with('success', 'Proposal onboarding Sales berhasil diajukan untuk persetujuan Superadmin.');
    }

    public function approve(SalesOnboardingDecisionRequest $request, SalesOnboardingProposal $sales_onboarding_proposal): RedirectResponse
    {
        $this->proposals->approve($sales_onboarding_proposal, $request->user(), $request->validated('decision_token'));

        return redirect()->route('admin-users.sales-proposals.index')->with('success', 'Proposal Sales disetujui dan akun berhasil diprovisikan.');
    }

    public function reject(SalesOnboardingDecisionRequest $request, SalesOnboardingProposal $sales_onboarding_proposal): RedirectResponse
    {
        $this->proposals->reject($sales_onboarding_proposal, $request->user(), $request->validated('decision_token'), $request->validated('reason'));

        return redirect()->route('admin-users.sales-proposals.index')->with('success', 'Proposal Sales ditolak.');
    }
}
