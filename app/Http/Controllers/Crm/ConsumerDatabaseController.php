<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsumerEntryRequest;
use App\Http\Requests\Crm\StoreConsumerLeadLinkDecisionRequest;
use App\Models\ActivityLog;
use App\Models\ConsumerApplication;
use App\Models\ConsumerLeadLinkDecision;
use App\Models\ConsumerNup;
use App\Models\KonsumenProgressSheetRow;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\ConsumerDatabaseWorkspaceService;
use App\Services\ConsumerEntryService;
use App\Services\ConsumerLeadLinkDecisionService;
use App\Services\ConsumerLeadLinkSubjectResolver;
use App\Services\ConsumerLeadLinkSuggestionService;
use App\Services\OrganizationScopeService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class ConsumerDatabaseController extends Controller
{
    public function __construct(
        private readonly ConsumerDatabaseWorkspaceService $workspace,
        private readonly ConsumerEntryService $entry,
        private readonly ConsumerLeadLinkDecisionService $leadLinkDecisions,
        private readonly ConsumerLeadLinkSuggestionService $leadLinkSuggestions,
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
            'nup' => $resolver->fromNup(ConsumerNup::query()->with('customer')->findOrFail($subjectId)),
            'sheet' => $resolver->fromSheetRow(KonsumenProgressSheetRow::query()->findOrFail($subjectId)),
            default => abort(404),
        };
        abort_unless($request->user()->isAccountActive() && $request->user()->hasPermission('consumer_progress.view') && $this->organizationScope->allowsProjectRecord($request->user(), 'consumer_progress', 'view', $subject->branchId, $subject->projectId), 403);
        $decisions = ConsumerLeadLinkDecision::query()->with('salesLead:id,customer_name')->where('subject_key', $subject->subjectKey())->latest('decided_at')->get();
        $active = $decisions->firstWhere('is_active', true);
        $suggestions = $this->leadLinkSuggestions->suggestSubject($subject, $request->user());

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
            'suggestions' => $suggestions->map(fn (array $item): array => ['lead_id' => $item['lead_id'], 'lead_name' => $item['lead']->customer_name, 'sales_name' => $item['lead']->sales?->name, 'score' => $item['score'], 'rationale' => $item['rationale']])->values()->all(),
            'current' => $active ? ['id' => $active->id, 'status' => $active->status, 'sales_lead_id' => $active->sales_lead_id, 'lead_name' => $active->salesLead?->customer_name, 'reason' => $active->reason] : null,
            'history' => $decisions->map(fn (ConsumerLeadLinkDecision $decision): array => ['id' => $decision->id, 'status' => $decision->status, 'sales_lead_id' => $decision->sales_lead_id, 'lead_name' => $decision->salesLead?->customer_name, 'reason' => $decision->reason, 'decided_at' => $decision->decided_at?->toIso8601String()])->values()->all(),
            'allowed_actions' => ['can_decide' => $request->user()->hasPermission('consumer_progress.manage')],
        ]]]);
    }

    public function linkConflicts(Request $request): JsonResponse|View
    {
        abort_unless($request->user()->hasPermission('consumer_progress.manage'), 403);
        $conflicts = $this->scopedConflicts($request->user());

        if (! $request->expectsJson()) {
            return view('crm.consumer-workspace.lead-link-conflicts', ['conflicts' => $conflicts]);
        }

        return response()->json(['ok' => true, 'data' => $conflicts]);
    }

    public function showLinkConflict(Request $request, int $conflict): View
    {
        abort_unless($request->user()->hasPermission('consumer_progress.manage'), 403);
        $item = $this->scopedConflicts($request->user())->firstWhere('id', $conflict);
        abort_unless($item !== null, 404);

        return view('crm.consumer-workspace.lead-link-conflict', ['conflict' => $item]);
    }

    public function resolveLinkConflict(Request $request, int $conflict): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('consumer_progress.manage'), 403);
        $item = $this->scopedConflicts($request->user())->firstWhere('id', $conflict);
        abort_unless($item !== null, 404);
        $data = $request->validate(['decision' => ['required', 'in:source,target'], 'reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($item, $data, $request): void {
            $sourceId = (int) $item->source_decision_id;
            $targetId = $item->target_decision_id === null ? null : (int) $item->target_decision_id;
            $selectedId = $data['decision'] === 'source' ? $sourceId : $targetId;
            abort_unless($selectedId !== null, 422, 'Keputusan tujuan tidak tersedia.');
            $decisions = ConsumerLeadLinkDecision::query()->whereIn('id', array_values(array_filter([$sourceId, $targetId])))->lockForUpdate()->get()->keyBy('id');
            $selected = $decisions->get($selectedId);
            abort_unless($selected !== null, 404);
            $decisions->each(fn (ConsumerLeadLinkDecision $decision): int => $decision->update(['is_active' => $decision->id === $selected->id]));
            DB::table('consumer_lead_link_conflicts')->where('id', $item->id)->update(['status' => 'resolved', 'details' => json_encode([...((array) $item->details), 'review_decision' => $data['decision'], 'review_reason_present' => true, 'reviewed_by' => $request->user()->id]), 'updated_at' => now()]);
            ActivityLog::create(['causer_id' => $request->user()->id, 'subject_type' => ConsumerApplication::class, 'subject_id' => $item->consumer_application_id, 'event' => 'consumer_lead_link_conflict_reviewed', 'description' => 'Konflik penautan Lead ditinjau.', 'properties' => ['conflict_id' => $item->id, 'decision' => $data['decision'], 'selected_decision_id' => $selected->id, 'reason_present' => true]]);
        });

        return redirect()->route('consumer-database.workspace.lead-link-conflict.show', $item->id)->with('success', 'Konflik berhasil ditinjau.');
    }

    private function scopedConflicts(User $user): Collection
    {
        $branchIds = $this->organizationScope->branchIds($user, 'consumer_progress');

        return DB::table('consumer_lead_link_conflicts as conflict')
            ->join('consumer_applications as application', 'application.id', '=', 'conflict.consumer_application_id')
            ->leftJoin('consumer_lead_link_decisions as source', 'source.id', '=', 'conflict.source_decision_id')
            ->leftJoin('consumer_lead_link_decisions as target', 'target.id', '=', 'conflict.target_decision_id')
            ->whereIn('application.branch_id', $branchIds)
            ->orderByDesc('conflict.id')
            ->limit(250)
            ->get(['conflict.id', 'conflict.source_decision_id', 'conflict.target_decision_id', 'conflict.consumer_application_id', 'conflict.conflict_type', 'conflict.status', 'conflict.details', 'conflict.created_at', 'source.status as source_status', 'source.sales_lead_id as source_lead_id', 'target.status as target_status', 'target.sales_lead_id as target_lead_id']);
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
