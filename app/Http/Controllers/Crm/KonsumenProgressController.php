<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\KonsumenProgressSyncStatus;
use App\Services\KonsumenPipelineService;
use App\Services\KonsumenProgressSyncService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class KonsumenProgressController extends Controller
{
    public function __construct(
        private readonly KonsumenPipelineService $pipelineService,
        private readonly WorkspaceAccessService $workspaceAccess,
    ) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedBranchId = $request->get('branch_id');

        $branches = $this->workspaceAccess->accessibleBranches($user);
        $selectedBranch = $this->workspaceAccess->resolveRequestedBranch($user, $selectedBranchId);
        if ($selectedBranchId && ! $selectedBranch) {
            abort(403);
        }
        $selectedBranch ??= $branches->first();
        $selectedBranchId = $selectedBranch?->id;
        $pipeline = [];
        $errors = [];
        $syncStatus = $selectedBranch
            ? KonsumenProgressSyncStatus::where('branch_id', $selectedBranch->id)->first()
            : null;
        $isStale = $syncStatus?->finished_at
            ? $syncStatus->finished_at->lt(now()->subMinutes((int) config('services.google_sheets.cache_stale_minutes', 30)))
            : true;

        if ($selectedBranch && $selectedBranch->sheet_id) {
            $pipeline = $this->pipelineService->buildPipeline($selectedBranch);
            if (array_sum(array_map('count', $pipeline)) === 0) {
                $errors[] = 'Data lokal belum tersedia. Klik Sync Sekarang terlebih dahulu.';
            }
        }

        return view('crm.konsumen-progress.index', compact('branches', 'selectedBranch', 'selectedBranchId', 'pipeline', 'errors', 'syncStatus', 'isStale'));
    }

    public function sync(Request $request, KonsumenProgressSyncService $syncService)
    {
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
        abort_unless($this->workspaceAccess->canSyncBranch(Auth::user(), $branch), 403);

        $result = $syncService->syncBranch($branch);
        if (! $result['ok']) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => 'Sync gagal: '.$result['message'], 'summary' => $result['summary'] ?? []], 422);
            }

            return back()->with('error', 'Sync gagal: '.$result['message']);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Sync selesai: '.array_sum($result['summary']).' rows diperbarui.',
                'summary' => $result['summary'],
                'finished_at' => now()->toIso8601String(),
            ]);
        }

        return back()->with('success', 'Sync selesai: '.array_sum($result['summary']).' rows diperbarui.');
    }

    public function stage(Request $request)
    {
        $stageKey = $request->query('stage', 'bast');
        if (! array_key_exists($stageKey, $this->pipelineService->stages())) {
            abort(404);
        }

        $branch = $this->resolveBranch($request);
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
