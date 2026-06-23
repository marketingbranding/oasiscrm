<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\GoogleScriptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class KonsumenProgressController extends Controller
{
    private GoogleScriptService $googleScript;

    private array $stages = [
        'bi_checking' => 'BI Checking',
        'PSJB' => 'PSJB',
        'pemberkasan' => 'Pemberkasan',
        'proses_bank' => 'Proses Bank',
        'ppjb_dev' => 'PPJB Dev',
        'akad' => 'Akad',
        'bast' => 'BAST',
    ];

    public function __construct(GoogleScriptService $googleScript)
    {
        $this->googleScript = $googleScript;
    }

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

        if ($selectedBranch && $selectedBranch->sheet_id) {
            $pipeline = $this->fetchPipeline($selectedBranch->sheet_id, $errors);
        }

        return view('crm.konsumen-progress.index', compact('branches', 'selectedBranch', 'selectedBranchId', 'pipeline', 'errors'));
    }

    private function fetchPipeline(string $sheetId, array &$errors): array
    {
        // Build nama lookup from data_konsumen tab
        $namaMap = [];
        $konsumenResult = $this->googleScript->fetchSheetCsv($sheetId, 'data_konsumen');
        if ($konsumenResult['success']) {
            foreach ($konsumenResult['data'] as $row) {
                $kav = trim($row['id_kavling'] ?? '');
                if ($kav !== '') {
                    $namaMap[$kav] = $row['nama_konsumen'] ?? null;
                }
            }
        } else {
            $errors[] = 'data_konsumen: ' . ($konsumenResult['error'] ?? 'Gagal memuat data');
        }

        // Fetch all stages from highest (bast) to lowest (bi_checking)
        // so first occurrence of id_kavling = highest stage
        $stageKeys = array_keys($this->stages);
        $stageKeysReversed = array_reverse($stageKeys);

        $seen = [];
        $pipeline = [];
        foreach ($stageKeysReversed as $stageKey) {
            $result = $this->googleScript->fetchSheetCsv($sheetId, $stageKey);

            if (!$result['success']) {
                $errors[] = $this->stages[$stageKey] . ': ' . ($result['error'] ?? 'Gagal memuat data');
                continue;
            }

            $rows = $result['data'] ?? [];
            $pipeline[$stageKey] = [];

            foreach ($rows as $row) {
                $kavling = trim($row['id_kavling'] ?? '');
                if ($kavling === '') continue;
                $nama = $namaMap[$kavling] ?? null;
                if ($nama === null) continue;
                if (isset($seen[$kavling])) continue;
                $seen[$kavling] = true;
                $pipeline[$stageKey][] = [
                    'kavling' => $kavling,
                    'nama' => $nama,
                ];
            }
        }

        // Fill missing stages with empty arrays
        foreach ($stageKeys as $key) {
            if (!isset($pipeline[$key])) {
                $pipeline[$key] = [];
            }
        }

        return $pipeline;
    }
}
