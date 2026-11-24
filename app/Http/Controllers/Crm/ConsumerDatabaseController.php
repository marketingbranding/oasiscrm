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
use Illuminate\Support\Facades\DB;
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

        return response()->json(['ok' => true, 'data' => ['subject' => [
            'subject_type' => $subject->subjectType,
            'subject_id' => $subject->subjectId ?? $subject->sheetRowId,
            'subject_key' => $subject->subjectKey(),
            'branch_id' => $subject->branchId,
            'project_id' => $subject->projectId,
            'name' => $subject->name,
            'phone' => $subject->phone,
            'kavling' => $subject->kavling,
            'sales_user_id' => $subject->salesUserId,
            'legacy_consumer_key' => $subject->legacyConsumerKey,
            'decidable' => $subject->isDecidable,
            'note' => $subject->note,
        ]]]);
    }

    public function linkConflicts(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('consumer_progress.manage'), 403);

        $branchIds = app(OrganizationScopeService::class)->branchIds($request->user(), 'consumer_progress');
        $conflicts = DB::table('consumer_lead_link_conflicts as conflict')
            ->join('consumer_applications as application', 'application.id', '=', 'conflict.consumer_application_id')
            ->whereIn('application.branch_id', $branchIds)
            ->orderByDesc('conflict.id')
            ->limit(250)
            ->get(['conflict.id', 'conflict.source_decision_id', 'conflict.target_decision_id', 'conflict.consumer_application_id', 'conflict.conflict_type', 'conflict.status', 'conflict.details', 'conflict.created_at']);

        return response()->json(['ok' => true, 'data' => $conflicts]);
    }

    public function previewBulkLegacy(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('consumer_progress.manage'), 403);

        $data = $request->validate(['branch_id' => ['required', 'integer'], 'project_id' => ['nullable', 'integer'], 'before' => ['required', 'date']]);

        return response()->json(['ok' => true, 'data' => $this->leadLinkDecisions->previewBulkLegacy($request->user(), array_replace($data, ['before' => (string) $data['before'], 'project_id' => isset($data['project_id']) ? (int) $data['project_id'] : null]))]);
    }

    public function storeBulkLegacy(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('consumer_progress.manage'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'branch_id' => ['required', 'integer'], 'project_id' => ['nullable', 'integer'], 'before' => ['required', 'date'], 'token' => ['required', 'string']]);

        return response()->json(['ok' => true, 'data' => $this->leadLinkDecisions->markBulkLegacy($request->user(), array_replace($data, ['before' => (string) $data['before'], 'branch_id' => (int) $data['branch_id'], 'project_id' => isset($data['project_id']) ? (int) $data['project_id'] : null]), $data['reason'])]);
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
