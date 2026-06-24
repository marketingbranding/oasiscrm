<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\GoogleScriptService;
<<<<<<< HEAD
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
=======
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
>>>>>>> a8222ac7249e5144acb855977bb858b5a31a9fa2

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
<<<<<<< HEAD
        $cacheKey = 'pipeline_' . $sheetId;

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($sheetId, &$errors) {
            $allNames = array_merge(['data_konsumen'], array_reverse(array_keys($this->stages)));

            // Check cache per tab first
            $webhookUrl = config('services.google_script.webhook_url');
            $results = [];
            $toFetch = [];

            foreach ($allNames as $name) {
                $tabCacheKey = 'google_sheet_csv_' . $sheetId . '_' . $name;
                $cached = Cache::get($tabCacheKey);
                if ($cached !== null && isset($cached['success']) && $cached['success']) {
                    $results[$name] = ['rows' => $cached['data'], 'error' => null];
                } else {
                    $toFetch[] = $name;
                }
            }

            // Fetch uncached tabs in parallel
            if (!empty($toFetch) && $webhookUrl) {
                $responses = Http::pool(function (Pool $pool) use ($webhookUrl, $sheetId, $toFetch) {
                    foreach ($toFetch as $name) {
                        $req = $pool->as($name)->timeout(30)
                            ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json']);
                        if (!config('services.google_script.verify_ssl')) {
                            $req = $req->withoutVerifying();
                        }
                        $req->get($webhookUrl, [
                            'type' => 'data',
                            'sheet_id' => $sheetId,
                            'sheet_name' => $name,
                        ]);
                    }
                });

                foreach ($toFetch as $name) {
                    $resp = $responses[$name] ?? null;
                    $tabCacheKey = 'google_sheet_csv_' . $sheetId . '_' . $name;

                    if ($resp instanceof \Illuminate\Http\Client\Response) {
                        if ($resp->ok() && str_contains($resp->header('Content-Type') ?? '', 'csv')) {
                            $body = $resp->body();
                            $lines = explode("\n", trim($body));
                            $rows = count($lines) >= 2 ? $this->parseCsv($lines) : [];
                            Cache::put($tabCacheKey, ['success' => true, 'data' => $rows], now()->addMinutes(5));
                            $results[$name] = ['rows' => $rows, 'error' => null];
                        } else {
                            $errMsg = 'HTTP ' . $resp->status() . ': ' . substr($resp->body(), 0, 200);
                            $results[$name] = ['rows' => [], 'error' => $errMsg];
                        }
                    } elseif ($resp instanceof \Throwable) {
                        $results[$name] = ['rows' => [], 'error' => $resp->getMessage()];
                    } else {
                        $results[$name] = ['rows' => [], 'error' => 'Tidak ada respons dari server'];
                    }
                }
            }

            // Build namaMap from data_konsumen
            $namaMap = [];
            $konsumenData = $results['data_konsumen'] ?? null;
            if ($konsumenData && !$konsumenData['error']) {
                foreach ($konsumenData['rows'] as $row) {
                    $kav = trim($row['id_kavling'] ?? '');
                    if ($kav !== '') {
                        $namaMap[$kav] = $row['nama_konsumen'] ?? null;
                    }
                }
            } else {
                $errors[] = 'data_konsumen: ' . ($konsumenData['error'] ?? 'Gagal memuat data');
            }

            // Process stages from highest to lowest
            $seen = [];
            $pipeline = [];
            foreach (array_reverse(array_keys($this->stages)) as $stageKey) {
                $data = $results[$stageKey] ?? null;
                $pipeline[$stageKey] = [];

                if ($data && $data['error']) {
                    $errors[] = $this->stages[$stageKey] . ': ' . $data['error'];
                    continue;
                }

                foreach (($data['rows'] ?? []) as $row) {
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
            foreach (array_keys($this->stages) as $key) {
                if (!isset($pipeline[$key])) {
                    $pipeline[$key] = [];
                }
            }

            return $pipeline;
        });
    }

    private function parseCsv(array $lines): array
    {
        $rawHeaders = str_getcsv(array_shift($lines));
        $headerIndices = [];
        $headers = [];
        $counts = [];
        foreach ($rawHeaders as $i => $h) {
            if ($h === '') continue;
            if (!isset($counts[$h])) $counts[$h] = 0;
            $counts[$h]++;
            $header = $counts[$h] > 1 ? $h . '_' . $counts[$h] : $h;
            $headerIndices[] = $i;
            $headers[] = $header;
        }
        $rows = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $cells = str_getcsv($line);
            $row = [];
            foreach ($headerIndices as $j => $idx) {
                $row[$headers[$j]] = $cells[$idx] ?? '';
            }
            $rows[] = $row;
        }
        return $rows;
=======
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
>>>>>>> a8222ac7249e5144acb855977bb858b5a31a9fa2
    }
}
