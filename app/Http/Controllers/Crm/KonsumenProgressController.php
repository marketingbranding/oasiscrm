<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\KonsumenProgressSheetRow;
use App\Models\KonsumenProgressSyncStatus;
use App\Services\KonsumenProgressSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class KonsumenProgressController extends Controller
{
    private array $stages = [
        'bi_checking' => 'BI Checking',
        'PSJB' => 'PSJB',
        'pemberkasan' => 'Pemberkasan',
        'proses_bank' => 'Proses Bank',
        'ppjb_dev' => 'PPJB Dev',
        'akad' => 'Akad',
        'bast' => 'BAST',
    ];

    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedBranchId = $request->get('branch_id');

        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();
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

        return view('crm.konsumen-progress.index', compact('branches', 'selectedBranch', 'selectedBranchId', 'pipeline', 'errors', 'syncStatus', 'isStale'));
    }

    public function sync(Request $request, KonsumenProgressSyncService $syncService)
    {
        $branch = $this->resolveBranch($request);
        if (!$branch) {
            return back()->with('error', 'Branch tidak ditemukan.');
        }

        $result = $syncService->syncBranch($branch);
        if (!$result['ok']) {
            return back()->with('error', 'Sync gagal: ' . $result['message']);
        }

        return back()->with('success', 'Sync selesai: ' . array_sum($result['summary']) . ' rows diperbarui.');
    }

    public function stage(Request $request)
    {
        $stageKey = $request->query('stage', 'bast');
        if (!array_key_exists($stageKey, $this->stages)) {
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

        $result = $this->fetchStage($branch, $stageKey);

        return response()->json([
            'ok' => $result['error'] === null,
            'items' => $result['items'],
            'count' => count($result['items']),
            'error' => $result['error'],
            'warnings' => $result['warnings'],
            'stale' => $result['stale'],
        ], $result['error'] ? 502 : 200);
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

    private function fetchStage(Branch $branch, string $stageKey): array
    {
        $warnings = [];
        $stale = $this->isBranchCacheStale($branch);
        $konsumenRows = $this->sheetRows($branch, 'data_konsumen');
        $stageRows = $this->sheetRows($branch, $stageKey);

        if (empty($konsumenRows)) {
            return [
                'items' => [],
                'error' => 'Data lokal belum tersedia. Klik Sync Sekarang terlebih dahulu.',
                'warnings' => [],
                'stale' => $stale,
            ];
        }

        $namaMap = [];
        $phoneMap = [];
        foreach ($konsumenRows as $row) {
            $kav = trim($row['id_kavling'] ?? '');
            if ($kav !== '') {
                $namaMap[$kav] = $row['nama_konsumen'] ?? null;
                $phoneMap[$kav] = $row['no_hp'] ?? null;
            }
        }

        $laterKavlings = [];
        foreach ($this->laterStages($stageKey) as $laterStage) {
            foreach ($this->sheetRows($branch, $laterStage) as $row) {
                $kavling = trim($row['id_kavling'] ?? '');
                if ($kavling !== '') {
                    $laterKavlings[$kavling] = true;
                }
            }
        }

        $items = [];
        $seen = [];
        foreach ($stageRows as $row) {
            $kavling = trim($row['id_kavling'] ?? '');
            if ($kavling === '' || isset($seen[$kavling]) || isset($laterKavlings[$kavling])) continue;

            $nama = $namaMap[$kavling] ?? null;
            if ($nama === null) continue;

            $seen[$kavling] = true;
            $items[] = [
                'kavling' => $kavling,
                'nama' => $nama,
                'phone' => $phoneMap[$kavling] ?? null,
            ];
        }

        return [
            'items' => $items,
            'error' => null,
            'warnings' => $warnings,
            'stale' => $stale,
        ];
    }

    private function laterStages(string $stageKey): array
    {
        $ordered = array_keys($this->stages);
        $index = array_search($stageKey, $ordered, true);

        return $index === false ? [] : array_slice($ordered, $index + 1);
    }

    private function sheetRows(Branch $branch, string $sheetName): array
    {
        return KonsumenProgressSheetRow::query()
            ->where('branch_id', $branch->id)
            ->where('sheet_name', $sheetName)
            ->orderBy('id')
            ->get()
            ->pluck('row_data')
            ->all();
    }

    private function isBranchCacheStale(Branch $branch): bool
    {
        $status = KonsumenProgressSyncStatus::where('branch_id', $branch->id)->first();

        return !$status?->finished_at
            || $status->finished_at->lt(now()->subMinutes((int) config('services.google_sheets.cache_stale_minutes', 30)));
    }
}
