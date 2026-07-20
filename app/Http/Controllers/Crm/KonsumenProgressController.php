<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\KonsumenProgressSyncStatus;
use App\Services\KonsumenPipelineService;
use App\Services\KonsumenProgressSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class KonsumenProgressController extends Controller
{
    public function __construct(private readonly KonsumenPipelineService $pipelineService) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedBranchId = $request->get('branch_id');

        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->forDropdown()->get();
            if ($selectedBranchId) {
                // use selected
            } elseif ($user->hasRole('pusat') && $user->branch_id) {
                $selectedBranchId = $user->branch_id;
            } elseif ($branches->isNotEmpty()) {
                $selectedBranchId = $branches->first()->id;
            }
        } else {
            $branches = collect();
            $selectedBranchId = $user->branch_id;
        }

        $selectedBranch = $selectedBranchId ? Branch::find($selectedBranchId) : null;
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
        if (!$branch) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => 'Branch tidak ditemukan.'], 422);
            }

            return back()->with('error', 'Branch tidak ditemukan.');
        }

        $result = $syncService->syncBranch($branch);
        if (!$result['ok']) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => 'Sync gagal: '.$result['message'], 'summary' => $result['summary'] ?? []], 422);
            }

            return back()->with('error', 'Sync gagal: ' . $result['message']);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Sync selesai: '.array_sum($result['summary']).' rows diperbarui.',
                'summary' => $result['summary'],
                'finished_at' => now()->toIso8601String(),
            ]);
        }

        return back()->with('success', 'Sync selesai: ' . array_sum($result['summary']) . ' rows diperbarui.');
    }

    public function stage(Request $request)
    {
        $stageKey = $request->query('stage', 'bast');
        if (! array_key_exists($stageKey, $this->pipelineService->stages())) {
            abort(404);
        }

        $branch = $this->resolveBranch($request);
        if (!$branch || !$branch->sheet_id) {
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
        $user = Auth::user();
        $branchId = $user->canViewAllBranches() ? $request->input('branch_id') : $user->branch_id;

        if (!$branchId && $user->canViewAllBranches()) {
            $branchId = Branch::where('is_active', true)->value('id');
        }

        return $branchId ? Branch::find($branchId) : null;
    }

    private function isBranchCacheStale(Branch $branch): bool
    {
        $status = KonsumenProgressSyncStatus::where('branch_id', $branch->id)->first();

        return !$status?->finished_at
            || $status->finished_at->lt(now()->subMinutes((int) config('services.google_sheets.cache_stale_minutes', 30)));
    }
}
