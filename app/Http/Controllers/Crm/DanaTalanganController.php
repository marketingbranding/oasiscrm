<?php

namespace App\Http\Controllers\Crm;

use App\Exports\DanaTalanganExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Crm\Traits\BulkOperations;
use App\Http\Controllers\Crm\Traits\Exportable;
use App\Http\Controllers\Crm\Traits\FilterableBranch;
use App\Http\Controllers\Crm\Traits\Importable;
use App\Http\Controllers\Crm\Traits\RedirectsShowToEdit;
use App\Http\Requests\Crm\StoreDanaTalanganRequest;
use App\Http\Requests\Crm\UpdateDanaTalanganRequest;
use App\Imports\DanaTalanganImport;
use App\Models\DanaTalangan;
use App\Models\DanaTalanganSyncStatus;
use App\Models\Kavling;
use App\Models\LeadMaster;
use App\Services\DanaTalanganGoogleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DanaTalanganController extends Controller
{
    use BulkOperations;
    use Exportable;
    use FilterableBranch;
    use Importable { importStore as private traitImportStore; }
    use RedirectsShowToEdit;

    protected string $showEditRoute = 'dana-talangan.edit';

    protected string $showEditParam = 'dana_talangan';

    protected string $exportClass = DanaTalanganExport::class;

    protected string $importView = 'crm.dana-talangan.import';

    protected string $importClass = DanaTalanganImport::class;

    protected array $importPreservedParams = ['branch_id', 'project_name', 'status'];

    protected string $importErrorRoute = 'dana-talangan.import';

    protected string $importSuccessRoute = 'dana-talangan.index';

    protected string $bulkModel = DanaTalangan::class;

    protected string $bulkLabel = 'data dana talangan';

    protected array $bulkStatusOptions = ['sanggup', 'tidak_sanggup', 'lunas'];

    protected string $bulkDefaultStatus = 'sanggup';

    protected string $bulkRedirectRoute = 'dana-talangan.index';

    protected array $bulkRedirectParams = ['branch_id', 'project_name', 'status', 'month'];

    public function index(Request $request, DanaTalanganGoogleService $googleService)
    {
        $user = Auth::user();
        $selectedBranchId = $this->resolveSelectedBranchId($request->get('branch_id'));
        $selectedProject = $request->get('project_name');
        $selectedStatus = $request->get('status');
        $monthTabs = $googleService->tabs();
        $selectedMonth = $request->get('month', $googleService->sheetNameForDate(now()) ?? 'Juli');
        if (! in_array($selectedMonth, $monthTabs, true)) {
            $selectedMonth = $monthTabs[0] ?? 'Juli';
        }

        $branches = $this->resolveBranches();
        $projects = $this->resolveBranchProjects($selectedBranchId);
        $query = $this->applyBranchScope(DanaTalangan::with(['branch', 'creator']), $selectedBranchId);

        if ($range = $googleService->dateRangeForSheet($selectedMonth)) {
            $query->whereBetween('tanggal', $range);
        }

        $query->when($selectedProject, fn ($q) => $q->where('project_name', $selectedProject));
        $query->when($selectedStatus, fn ($q) => $q->where('status', $selectedStatus));
        $query->when($request->get('search'), fn ($q, $v) => $q->where(function ($q) use ($v) {
            $q->where('nama_konsumen', 'like', "%{$v}%")
                ->orWhere('kav', 'like', "%{$v}%")
                ->orWhere('project_name', 'like', "%{$v}%")
                ->orWhere('nama_marketing', 'like', "%{$v}%")
                ->orWhere('penyelesaian', 'like', "%{$v}%");
        }));

        $sortField = $request->get('sort', 'tanggal');
        $sortDir = $request->get('dir', 'desc');
        $allowedSorts = ['tanggal', 'nama_konsumen', 'kav', 'project_name', 'pekerjaan', 'status_perkawinan', 'umur', 'nama_marketing', 'tgl_komitmen', 'status'];
        if (! in_array($sortField, $allowedSorts)) {
            $sortField = 'tanggal';
        }
        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        $perPage = $request->get('per_page', '15');
        if ($perPage === 'all') {
            $records = $query->orderBy($sortField, $sortDir)->get();
        } else {
            $records = $query->orderBy($sortField, $sortDir)->paginate((int) $perPage)->withQueryString();
        }

        $kavlings = Kavling::with('project')->orderBy('kavling_code')->get();
        $syncStatus = DanaTalanganSyncStatus::where('spreadsheet_id', config('services.google_sheets.dana_talangan_spreadsheet_id'))->first();

        return view('crm.dana-talangan.index', compact('records', 'branches', 'projects', 'kavlings', 'selectedBranchId', 'selectedProject', 'selectedStatus', 'sortField', 'sortDir', 'perPage', 'monthTabs', 'selectedMonth', 'syncStatus'));
    }

    public function create()
    {
        $user = Auth::user();
        $branches = $this->resolveBranches();

        if ($user->canViewAllBranches()) {
            $projects = LeadMaster::where('is_active', true)->orderBy('project_name')->get();
        } else {
            $projects = LeadMaster::where('is_active', true)
                ->where('branch_id', $user->branch_id)
                ->orderBy('project_name')
                ->get();
        }

        $kavlings = Kavling::with('project')->orderBy('kavling_code')->get();

        return view('crm.dana-talangan.create', compact('branches', 'projects', 'kavlings'));
    }

    public function store(StoreDanaTalanganRequest $request, DanaTalanganGoogleService $googleService)
    {
        $user = Auth::user();
        $data = $request->validated();

        if (! $user->canViewAllBranches()) {
            $data['branch_id'] = $user->branch_id;
        }

        $projectBranchId = LeadMaster::where('is_active', true)
            ->where('project_name', $data['project_name'])
            ->where('branch_id', $data['branch_id'])
            ->value('branch_id');
        if (! $projectBranchId) {
            return back()->withInput()->withErrors(['project_name' => 'Proyek tidak terdaftar pada cabang yang dipilih.']);
        }
        $data['branch_id'] = $projectBranchId;

        $data['pinjam_nama'] = $request->boolean('pinjam_nama');
        $data['konfirmasi_keuangan'] = $request->boolean('konfirmasi_keuangan');
        $data['created_by'] = $user->id;

        $record = DanaTalangan::create($data);
        if (! $googleService->push($record, $user->id)) {
            return redirect()->route('dana-talangan.index', ['month' => $googleService->sheetNameForDate($record->tanggal)])
                ->with('error', 'Data lokal tersimpan, tetapi gagal dikirim ke Google Sheets: '.$record->last_sync_error);
        }

        return redirect()->route('dana-talangan.index', array_filter($request->only(['branch_id', 'project_name', 'status', 'month'])))
            ->with('success', 'Data dana talangan berhasil ditambahkan.');
    }

    public function edit(DanaTalangan $danaTalangan)
    {
        $user = Auth::user();
        if (! $user->canViewAllBranches() && $danaTalangan->branch_id !== $user->branch_id) {
            abort(403);
        }

        $branches = $this->resolveBranches();
        if ($user->canViewAllBranches()) {
            $projects = LeadMaster::where('is_active', true)->orderBy('project_name')->get();
        } else {
            $projects = LeadMaster::where('is_active', true)
                ->where('branch_id', $user->branch_id)
                ->orderBy('project_name')
                ->get();
        }

        $record = $danaTalangan;
        $kavlings = Kavling::with('project')->orderBy('kavling_code')->get();

        return view('crm.dana-talangan.edit', compact('record', 'branches', 'projects', 'kavlings'));
    }

    public function update(UpdateDanaTalanganRequest $request, DanaTalangan $danaTalangan, DanaTalanganGoogleService $googleService)
    {
        $user = Auth::user();
        $data = $request->validated();

        if (! $user->canViewAllBranches()) {
            $data['branch_id'] = $user->branch_id;
        }

        $projectBranchId = LeadMaster::where('is_active', true)
            ->where('project_name', $data['project_name'])
            ->where('branch_id', $data['branch_id'])
            ->value('branch_id');
        if (! $projectBranchId) {
            return back()->withInput()->withErrors(['project_name' => 'Proyek tidak terdaftar pada cabang yang dipilih.']);
        }
        $data['branch_id'] = $projectBranchId;

        $data['pinjam_nama'] = $request->boolean('pinjam_nama');
        $data['konfirmasi_keuangan'] = $request->boolean('konfirmasi_keuangan');

        $danaTalangan->update($data);
        if (! $googleService->push($danaTalangan, $user->id)) {
            return redirect()->route('dana-talangan.index', ['month' => $googleService->sheetNameForDate($danaTalangan->tanggal)])
                ->with('error', 'Data lokal diperbarui, tetapi gagal dikirim ke Google Sheets: '.$danaTalangan->last_sync_error);
        }

        return redirect()->route('dana-talangan.index', array_filter($request->only(['branch_id', 'project_name', 'status', 'month'])))
            ->with('success', 'Data dana talangan berhasil diperbarui.');
    }

    public function export(Request $request, DanaTalanganGoogleService $googleService)
    {
        $user = Auth::user();
        $query = DanaTalangan::with(['branch', 'creator']);

        if (! $user->canViewAllBranches()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $query->when($request->get('project_name'), fn ($q, $v) => $q->where('project_name', $v));
        $query->when($request->get('status'), fn ($q, $v) => $q->where('status', $v));
        if ($request->filled('month') && ($range = $googleService->dateRangeForSheet($request->month))) {
            $query->whereBetween('tanggal', $range);
        }

        $records = $query->latest('tanggal')->get();

        $filename = 'dana-talangan-'.now()->format('Y-m-d').'.xlsx';

        return DanaTalanganExport::toBrowser($records, $filename);
    }

    public function detail(DanaTalangan $danaTalangan)
    {
        $user = Auth::user();
        if (! $user->canViewAllBranches() && $danaTalangan->branch_id !== $user->branch_id) {
            abort(403);
        }

        $danaTalangan->load('creator');

        return response()->json($danaTalangan);
    }

    public function destroy(DanaTalangan $danaTalangan, DanaTalanganGoogleService $googleService)
    {
        $user = Auth::user();
        if (! $user->canViewAllBranches() && $danaTalangan->branch_id !== $user->branch_id) {
            abort(403);
        }

        if (! $googleService->delete($danaTalangan, $user->id)) {
            return back()->with('error', 'Gagal menghapus data dari Google Sheets: '.$danaTalangan->last_sync_error);
        }

        return redirect()->route('dana-talangan.index', array_filter(request()->only(['branch_id', 'project_name', 'status'])))
            ->with('success', 'Data dana talangan berhasil dihapus.');
    }

    public function sync(DanaTalanganGoogleService $googleService)
    {
        $result = $googleService->sync(Auth::id());
        if (! $result['ok']) {
            return back()->with('error', 'Sync Dana Talangan gagal: '.$result['message']);
        }

        $summary = $result['summary'];

        return back()->with('success', "Sync selesai: {$summary['updated']} diperbarui, {$summary['imported']} diimpor, {$summary['pushed']} dikirim.");
    }

    public function importStore(Request $request, DanaTalanganGoogleService $googleService)
    {
        $response = $this->traitImportStore($request);
        $googleService->sync(Auth::id());

        return $response;
    }

    public function bulkDestroy(Request $request, DanaTalanganGoogleService $googleService)
    {
        $records = $this->bulkRecords($request);
        $deleted = 0;
        foreach ($records as $record) {
            if ($googleService->delete($record, Auth::id())) {
                $deleted++;
            }
        }

        return back()->with('success', "{$deleted} data dana talangan berhasil dihapus.");
    }

    public function bulkUpdate(Request $request, DanaTalanganGoogleService $googleService)
    {
        $status = $request->validate(['new_status' => 'required|in:sanggup,tidak_sanggup,lunas'])['new_status'];
        $updated = 0;
        foreach ($this->bulkRecords($request) as $record) {
            $record->update(['status' => $status]);
            if ($googleService->push($record, Auth::id())) {
                $updated++;
            }
        }

        return back()->with('success', "{$updated} data dana talangan berhasil diperbarui.");
    }

    private function bulkRecords(Request $request)
    {
        $ids = array_filter(explode(',', $request->input('selected_ids', '')));
        if (empty($ids)) {
            return collect();
        }

        return $this->applyBranchScope(DanaTalangan::whereIn('id', $ids), null)->get();
    }
}
