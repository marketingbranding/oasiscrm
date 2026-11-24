<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsumerEntryRequest;
use App\Http\Requests\Crm\StoreConsumerLeadLinkDecisionRequest;
use App\Models\ConsumerApplication;
use App\Models\ConsumerNup;
use App\Models\KonsumenProgressSheetRow;
use App\Models\SalesLead;
use App\Services\ConsumerDatabaseWorkspaceService;
use App\Services\ConsumerEntryService;
use App\Services\ConsumerLeadLinkDecisionService;
use App\Services\ConsumerLeadLinkSubjectResolver;
use App\Services\OrganizationScopeService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ConsumerDatabaseController extends Controller
{
    public function __construct(
        private readonly ConsumerDatabaseWorkspaceService $workspace,
        private readonly ConsumerEntryService $entry,
        private readonly ConsumerLeadLinkDecisionService $leadLinkDecisions,
        private readonly OrganizationScopeService $organizationScope,
    ) {}

    public function index(Request $request): View
    {
        return view('crm.consumer-workspace.index', [...$this->workspace->index($request->user(), $request), 'processTitle' => null]);
    }

    public function create(Request $request): View
    {
        return view('crm.consumer-workspace.create', [
            'branches' => app(WorkspaceAccessService::class)->accessibleBranches($request->user()),
            'projects' => app(WorkspaceAccessService::class)->accessibleProjects($request->user()),
        ]);
    }

    public function store(ConsumerEntryRequest $request): RedirectResponse|JsonResponse
    {
        $application = $this->entry->create($request->validated(), $request->user());
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'data' => ['id' => $application->id, 'id_transaksi' => $application->id_transaksi]], 201);
        }

        return redirect()->route('consumer-database.workspace.show', $application)->with('success', 'Data konsumen berhasil disimpan.');
    }

    public function show(Request $request, ConsumerApplication $consumerApplication): JsonResponse
    {
        $detail = $this->workspace->detail($request->user(), $consumerApplication);
        $detail['actions'] = [
            'return_to_nup' => $detail['can_input'] ? route('consumer-nups.return', $consumerApplication) : null,
            'resolve_issue' => $detail['can_input'] ? route('consumer-issues.resolve', ['consumerIssue' => '__ISSUE__']) : null,
        ];

        return response()->json([
            'ok' => true,
            'data' => $detail,
        ]);
    }

    public function showLinkSubject(Request $request, string $subjectType, int $subjectId): JsonResponse
    {
        $resolver = app(ConsumerLeadLinkSubjectResolver::class);
        $subject = match ($subjectType) {
            'nup' => $resolver->fromNup(ConsumerNup::query()->findOrFail($subjectId)),
            'sheet' => $resolver->fromSheetRow(KonsumenProgressSheetRow::query()->findOrFail($subjectId)),
            default => abort(404),
        };
        abort_unless($request->user()->isAccountActive() && $this->organizationScope->allowsProjectRecord($request->user(), 'consumer_progress', 'view', $subject->branchId, $subject->projectId), 403);

        return response()->json(['ok' => true, 'data' => ['subject' => get_object_vars($subject)]]);
    }

    public function storeLeadLinkDecision(StoreConsumerLeadLinkDecisionRequest $request, ?ConsumerApplication $consumerApplication = null): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();
        $lead = isset($validated['sales_lead_id']) ? SalesLead::query()->findOrFail($validated['sales_lead_id']) : null;
        $subject = $request->linkSubject();
        $decision = $subject !== null
            ? $this->leadLinkDecisions->decideForSubject($subject, $validated['status'], $lead, $validated, $request->user())
            : $this->leadLinkDecisions->decide($consumerApplication, $validated['status'], $lead, $validated, $request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Keputusan penautan Lead berhasil disimpan.',
                'data' => [
                    'id' => $decision->id,
                    'status' => $decision->status,
                ],
            ]);
        }

        return back()->with('success', 'Keputusan penautan Lead berhasil disimpan.');
    }
}
