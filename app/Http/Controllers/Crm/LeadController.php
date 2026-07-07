<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Crm\Traits\FilterableBranch;
use App\Http\Controllers\Crm\Traits\RedirectsShowToEdit;
use App\Models\Branch;
use App\Models\CampaignOption;
use App\Models\Lead;
use App\Models\LeadMaster;
use App\Models\LeadSource;
use App\Models\PlatformOption;
use App\Models\StatusLeadOption;
use App\Services\GoogleScriptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    use FilterableBranch;
    use RedirectsShowToEdit;

    protected string $showEditRoute = 'leads.edit';
    protected string $showEditParam = 'lead';

    private GoogleScriptService $googleScript;

    public function __construct(GoogleScriptService $googleScript)
    {
        $this->googleScript = $googleScript;
    }

    public function index(Request $request)
    {
        $selectedBranchId = $this->resolveSelectedBranchId($request->get('branch_id'));
        $selectedProjectName = $request->get('project_name');

        $branches = $this->resolveBranches();
        $projects = $this->resolveBranchProjects($selectedBranchId);
        $query = $this->applyBranchScope(Lead::with(['branch', 'creator']), $selectedBranchId);

        if ($selectedProjectName) {
            $query->where('proyek', $selectedProjectName);
        }

        $query->when($request->get('search'), fn($q, $v) => $q->where(function($q) use ($v) {
            $q->where('id_lead', 'like', "%{$v}%")
              ->orWhere('nama_konsumen', 'like', "%{$v}%")
              ->orWhere('no_hp', 'like', "%{$v}%")
              ->orWhere('proyek', 'like', "%{$v}%")
              ->orWhere('sumber', 'like', "%{$v}%")
              ->orWhere('sales_pic', 'like', "%{$v}%")
              ->orWhere('status_lead', 'like', "%{$v}%");
        }));

        $sortField = $request->get('sort', 'tanggal_lead');
        $sortDir = $request->get('dir', 'desc');
        $allowedSorts = ['id_lead', 'tanggal_lead', 'sumber', 'platform', 'nama_konsumen', 'proyek', 'sales_pic', 'status_lead'];
        if (!in_array($sortField, $allowedSorts)) $sortField = 'tanggal_lead';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';

        $perPage = $request->get('per_page', '15');
        if ($perPage === 'all') {
            $leads = $query->orderBy($sortField, $sortDir)->get();
        } else {
            $leads = $query->orderBy($sortField, $sortDir)->paginate((int) $perPage)->withQueryString();
        }

        $sources = LeadSource::where('is_active', true)->orderBy('name')->pluck('name');
        $platforms = PlatformOption::where('is_active', true)->orderBy('name')->pluck('name');
        $campaigns = CampaignOption::where('is_active', true)->orderBy('name')->pluck('name');
        $statuses = StatusLeadOption::where('is_active', true)->orderBy('name')->pluck('name');

        return view('crm.leads.index', compact(
            'leads', 'branches', 'selectedBranchId', 'selectedProjectName',
            'projects', 'sortField', 'sortDir', 'perPage',
            'sources', 'platforms', 'campaigns', 'statuses'
        ));
    }

    public function create()
    {
        $branches = $this->resolveBranches();
        $projects = $this->resolveBranchProjects();
        $sources = LeadSource::where('is_active', true)->orderBy('name')->get();
        $platforms = PlatformOption::where('is_active', true)->orderBy('name')->pluck('name');
        $campaigns = CampaignOption::where('is_active', true)->orderBy('name')->pluck('name');
        $statuses = StatusLeadOption::where('is_active', true)->orderBy('name')->pluck('name');

        $salesPics = $this->fetchSalesPics();
        $promos = $this->fetchPromos();

        return view('crm.leads.create', compact(
            'branches', 'projects', 'sources', 'platforms',
            'campaigns', 'statuses', 'salesPics', 'promos'
        ));
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $data = $request->validate([
            'branch_id' => $user->canViewAllBranches() ? 'required|exists:branches,id' : 'nullable',
            'id_promo' => 'nullable|string|max:50',
            'tanggal_lead' => 'required|date',
            'sumber' => 'required|string|max:100',
            'platform' => 'required|string|max:100',
            'campaign' => 'required|string|max:100',
            'nama_konsumen' => 'required|string|max:255',
            'no_hp' => 'nullable|string|max:50',
            'proyek' => 'required|string|max:255',
            'sales_pic' => 'required|string|max:100',
            'status_lead' => 'required|string|max:100',
            'keterangan' => 'nullable|string',
        ]);

        if (!$user->canViewAllBranches()) {
            $data['branch_id'] = $user->branch_id;
        }

        $data['created_by'] = $user->id;
        $data['id_lead'] = Lead::generateIdLead($data['tanggal_lead'], $data['sumber'], $data['platform']);

        Lead::create($data);

        return redirect()->route('leads.index', array_filter($request->only(['branch_id', 'proyek'])))
            ->with('success', 'Lead berhasil ditambahkan.');
    }

    public function edit(Lead $lead)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $lead->branch_id !== $user->branch_id) {
            abort(403);
        }

        $branches = $this->resolveBranches();
        $projects = $this->resolveBranchProjects($lead->branch_id);
        $allProjects = LeadMaster::where('is_active', true)->orderBy('project_name')->get();
        $sources = LeadSource::where('is_active', true)->orderBy('name')->get();
        $platforms = PlatformOption::where('is_active', true)->orderBy('name')->pluck('name');
        $campaigns = CampaignOption::where('is_active', true)->orderBy('name')->pluck('name');
        $statuses = StatusLeadOption::where('is_active', true)->orderBy('name')->pluck('name');
        $salesPics = $this->fetchSalesPics();
        $promos = $this->fetchPromos();

        return view('crm.leads.edit', compact(
            'lead', 'branches', 'projects', 'allProjects', 'sources', 'platforms',
            'campaigns', 'statuses', 'salesPics', 'promos'
        ));
    }

    public function update(Request $request, Lead $lead)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $lead->branch_id !== $user->branch_id) {
            abort(403);
        }

        $data = $request->validate([
            'branch_id' => $user->canViewAllBranches() ? 'required|exists:branches,id' : 'nullable',
            'id_promo' => 'nullable|string|max:50',
            'tanggal_lead' => 'required|date',
            'sumber' => 'required|string|max:100',
            'platform' => 'required|string|max:100',
            'campaign' => 'required|string|max:100',
            'nama_konsumen' => 'required|string|max:255',
            'no_hp' => 'nullable|string|max:50',
            'proyek' => 'required|string|max:255',
            'sales_pic' => 'required|string|max:100',
            'status_lead' => 'required|string|max:100',
            'keterangan' => 'nullable|string',
        ]);

        if (!$user->canViewAllBranches()) {
            $data['branch_id'] = $user->branch_id;
        }

        $lead->update($data);

        return redirect()->route('leads.index', array_filter($request->only(['branch_id', 'proyek'])))
            ->with('success', 'Lead berhasil diperbarui.');
    }

    public function detail(Lead $lead)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $lead->branch_id !== $user->branch_id) {
            abort(403);
        }

        $lead->load('creator');
        return response()->json($lead);
    }

    public function destroy(Lead $lead)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $lead->branch_id !== $user->branch_id) {
            abort(403);
        }

        $lead->delete();

        return redirect()->route('leads.index', array_filter(request()->only(['branch_id', 'proyek'])))
            ->with('success', 'Lead berhasil dihapus.');
    }

    public function bulkDestroy(Request $request)
    {
        $ids = array_filter(explode(',', $request->input('selected_ids', '')));
        if (empty($ids)) {
            return back()->with('error', 'Tidak ada data yang dipilih.');
        }

        $user = Auth::user();
        $query = Lead::whereIn('id', $ids);
        if (!$user->canViewAllBranches()) {
            $query->where('branch_id', $user->branch_id);
        }

        $count = $query->delete();

        return redirect()->route('leads.index', array_filter($request->only(['branch_id', 'proyek'])))
            ->with('success', "$count lead berhasil dihapus.");
    }

    public function export(Request $request)
    {
        $user = Auth::user();
        $query = Lead::with('branch');

        if (!$user->canViewAllBranches()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('proyek')) {
            $query->where('proyek', $request->proyek);
        }

        $leads = $query->orderBy('tanggal_lead', 'desc')->get();

        $filename = 'leads-' . now()->format('Ymd') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($leads) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['ID Lead', 'ID Promo', 'Tanggal Lead', 'Sumber', 'Platform', 'Campaign', 'Nama Konsumen', 'No HP', 'Proyek', 'Sales PIC', 'Status Lead', 'Keterangan', 'Cabang']);

            foreach ($leads as $lead) {
                fputcsv($file, [
                    $lead->id_lead,
                    $lead->id_promo ?? '',
                    $lead->tanggal_lead->format('d M Y'),
                    $lead->sumber,
                    $lead->platform,
                    $lead->campaign,
                    $lead->nama_konsumen,
                    $lead->no_hp ?? '',
                    $lead->proyek,
                    $lead->sales_pic,
                    $lead->status_lead,
                    $lead->keterangan ?? '',
                    $lead->branch->name ?? '',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function fetchSalesPics(): array
    {
        $user = Auth::user();
        $branchId = $user->canViewAllBranches() ? null : $user->branch_id;
        $branch = $branchId ? Branch::find($branchId) : null;
        if (!$branch || !$branch->sheet_id) {
            return [];
        }

        $cacheKey = 'sales_pics_' . $branch->sheet_id;
        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($branch) {
            $webhookUrl = config('services.google_script.webhook_url');
            if (!$webhookUrl) return [];

            try {
                $response = Http::timeout(10)->get($webhookUrl, [
                    'type' => 'data',
                    'sheet_id' => $branch->sheet_id,
                    'sheet_name' => 'data_sales',
                ]);

                if (!$response->ok()) return [];

                $lines = explode("\n", trim($response->body()));
                if (count($lines) < 2) return [];

                $headers = str_getcsv(array_shift($lines));
                $names = [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $cells = str_getcsv($line);
                    $row = array_combine($headers, $cells);
                    $name = trim($row['name'] ?? $row['Nama'] ?? $row['Sales'] ?? '');
                    if ($name !== '') $names[] = $name;
                }
                return $names;
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    private function fetchPromos(): array
    {
        $user = Auth::user();
        $branchId = $user->canViewAllBranches() ? null : $user->branch_id;
        $branch = $branchId ? Branch::find($branchId) : null;
        if (!$branch || !$branch->sheet_id) {
            return [];
        }

        $cacheKey = 'promos_' . $branch->sheet_id;
        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($branch) {
            $webhookUrl = config('services.google_script.webhook_url');
            if (!$webhookUrl) return [];

            try {
                $response = Http::timeout(10)->get($webhookUrl, [
                    'type' => 'data',
                    'sheet_id' => $branch->sheet_id,
                    'sheet_name' => 'promo',
                ]);

                if (!$response->ok()) return [];

                $lines = explode("\n", trim($response->body()));
                if (count($lines) < 2) return [];

                $headers = str_getcsv(array_shift($lines));
                $promos = [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $cells = str_getcsv($line);
                    $row = array_combine($headers, $cells);
                    $promo = trim($row['id_promo'] ?? $row['ID Promo'] ?? $row['promo'] ?? $row['Promo'] ?? '');
                    if ($promo !== '') $promos[] = $promo;
                }
                return $promos;
            } catch (\Exception $e) {
                return [];
            }
        });
    }
}
