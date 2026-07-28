<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\KonsumenProgressSyncStatus;
use App\Services\CollaborationNotificationService;
use App\Services\KonsumenPipelineService;
use App\Services\KonsumenProgressSyncService;
use App\Services\OrganizationScopeService;
use App\Services\SyncResponseService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class KonsumenProgressController extends Controller
{
    public function __construct(
        private readonly KonsumenPipelineService $pipelineService,
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly CollaborationNotificationService $notifications,
        private readonly SyncResponseService $syncResponses,
        private readonly OrganizationScopeService $organizationScope,
    ) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedBranchId = $request->get('branch_id');

        $allowedBranchIds = $this->organizationScope->branchIds($user, 'consumer_progress');
        $branches = $this->workspaceAccess->accessibleBranches($user)->whereIn('id', $allowedBranchIds)->values();
        $selectedBranch = $this->workspaceAccess->resolveRequestedBranch($user, $selectedBranchId);
        if ($selectedBranchId && (! $selectedBranch || ! in_array((int) $selectedBranch->id, $allowedBranchIds, true))) {
            abort(403);
        }
        $selectedBranch ??= $branches->first();
        $selectedBranchId = $selectedBranch?->id;
        $pipeline = [];
        $errors = [];
        $syncStatus = $selectedBranch
            ? KonsumenProgressSyncStatus::where('branch_id', $selectedBranch->id)->first()
            : null;
        $isStale = $syncStatus?->status !== 'success' || ! $syncStatus?->finished_at
            || $syncStatus->finished_at->lt(now()->subMinutes((int) config('services.google_sheets.cache_stale_minutes', 30)));

        if ($selectedBranch && $selectedBranch->sheet_id) {
            $pipeline = $this->pipelineService->buildPipeline($selectedBranch);
            if (array_sum(array_map('count', $pipeline)) === 0) {
                $errors[] = 'Data lokal belum tersedia. Klik Sync Sekarang terlebih dahulu.';
            }
        }

        $canSync = $user->hasPermission('consumer_progress.sync') && $selectedBranch && $this->workspaceAccess->canSyncBranch($user, $selectedBranch);

        return view('crm.konsumen-progress.index', compact('branches', 'selectedBranch', 'selectedBranchId', 'pipeline', 'errors', 'syncStatus', 'isStale', 'canSync'));
    }

    public function sync(Request $request)
    {
        abort_unless($request->user()->hasPermission('consumer_progress.sync'), 403);
        $branch = $this->resolveBranch($request);
        if ($request->filled('branch_id') && ! $branch) {
            abort(403);
        }
        if (! $branch) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => 'Branch tidak ditemukan.'], 422);
            }

            return back()->with('error', 'Branch tidak ditemukan.');
        }
        abort_unless(in_array((int) $branch->id, $this->organizationScope->branchIds($request->user(), 'consumer_progress', 'sync'), true), 403);
        abort_unless($this->workspaceAccess->canSyncBranch(Auth::user(), $branch), 403);

        try {
            $result = app(KonsumenProgressSyncService::class)->syncBranch($branch, Auth::id());
        } catch (Throwable $exception) {
            report($exception);
            $result = ['ok' => false, 'message' => 'Layanan sinkronisasi tidak dapat dijalankan.', 'summary' => []];
        }
        $status = KonsumenProgressSyncStatus::with('initiator')->where('branch_id', $branch->id)->first();
        $payload = $this->syncResponses->make('konsumen-progress', ['type' => 'branch', 'id' => $branch->id, 'name' => $branch->name], $status, $result);
        $payload['status_url'] = route('konsumen-progress.sync-status', ['branch_id' => $branch->id]);
        if (($result['code'] ?? null) !== 'sync_already_running') {
            $this->notifications->syncResult(Auth::user(), 'Konsumen Progress', $branch->name, $result, route('konsumen-progress.index', ['branch_id' => $branch->id]), array_sum($result['summary'] ?? []));
        }

        if ($request->expectsJson()) {
            return response()->json($payload, ($result['code'] ?? null) === 'sync_already_running' ? 409 : ($payload['status'] === 'failed' ? 422 : 200));
        }

        return back()->with($payload['status'] === 'success' ? 'success' : 'error', $payload['message']);
    }

    public function syncStatus(Request $request)
    {
        abort_unless($request->user()->hasPermission('consumer_progress.sync'), 403);
        $branch = $this->resolveBranch($request);
        abort_unless($branch && $this->workspaceAccess->canSyncBranch($request->user(), $branch), 403);
        abort_unless(in_array((int) $branch->id, $this->organizationScope->branchIds($request->user(), 'consumer_progress', 'sync'), true), 403);
        $status = KonsumenProgressSyncStatus::with('initiator')->where('branch_id', $branch->id)->first();
        $payload = $this->syncResponses->make('konsumen-progress', ['type' => 'branch', 'id' => $branch->id, 'name' => $branch->name], $status);
        $payload['status_url'] = route('konsumen-progress.sync-status', ['branch_id' => $branch->id]);

        return response()->json($payload);
    }

    public function stage(Request $request)
    {
        abort_unless($request->user()->hasPermission('consumer_progress.view'), 403);
        $stageKey = $request->query('stage', 'bast');
        if (! array_key_exists($stageKey, $this->pipelineService->stages())) {
            abort(404);
        }

        $branch = $this->resolveBranch($request);
        abort_unless(! $branch || in_array((int) $branch->id, $this->organizationScope->branchIds($request->user(), 'consumer_progress'), true), 403);
        if (! $branch || ! $branch->sheet_id) {
            return response()->json([
                'ok' => false,
                'items' => [],
                'count' => 0,
                'error' => 'Database branch belum tersedia.',
            ], 422);
        }

        $items = $this->pipelineService->customersForStage($branch, $stageKey);

        return response()->json([
            'ok' => true,
            'items' => $items,
            'count' => count($items),
            'error' => null,
            'warnings' => [],
            'stale' => $this->isBranchCacheStale($branch),
        ]);
    }

    private function resolveBranch(Request $request): ?Branch
    {
        return $this->workspaceAccess->resolveRequestedBranch(Auth::user(), $request->input('branch_id') ?? $request->query('branch_id'));
    }

    private function isBranchCacheStale(Branch $branch): bool
    {
        $status = KonsumenProgressSyncStatus::where('branch_id', $branch->id)->first();

        return ! $status?->finished_at
            || $status->finished_at->lt(now()->subMinutes((int) config('services.google_sheets.cache_stale_minutes', 30)));
    }
}
