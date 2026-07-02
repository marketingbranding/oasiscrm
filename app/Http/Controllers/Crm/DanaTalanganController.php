<?php

namespace App\Http\Controllers\Crm;

use App\Exports\DanaTalanganExport;
use App\Imports\DanaTalanganImport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Crm\Traits\FilterableBranch;
use App\Http\Controllers\Crm\Traits\RedirectsShowToEdit;
use App\Http\Controllers\Crm\Traits\Exportable;
use App\Http\Controllers\Crm\Traits\Importable;
use App\Http\Controllers\Crm\Traits\BulkOperations;
use App\Http\Requests\Crm\StoreDanaTalanganRequest;
use App\Http\Requests\Crm\UpdateDanaTalanganRequest;
use App\Models\Branch;
use App\Models\DanaTalangan;
use App\Models\Kavling;
use App\Models\LeadMaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DanaTalanganController extends Controller
{
    use FilterableBranch;
    use RedirectsShowToEdit;
    use Exportable;
    use Importable;
    use BulkOperations;

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
    protected array $bulkStatusOptions = ['aktif', 'lunas'];
    protected string $bulkDefaultStatus = 'aktif';
    protected string $bulkRedirectRoute = 'dana-talangan.index';
    protected array $bulkRedirectParams = ['branch_id', 'project_name', 'status'];

    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedBranchId = $this->resolveSelectedBranchId($request->get('branch_id'));
        $selectedProject = $request->get('project_name');
        $selectedStatus = $request->get('status');

        $branches = $this->resolveBranches();
        $projects = $this->resolveBranchProjects($selectedBranchId);
        $query = $this->applyBranchScope(DanaTalangan::with(['branch', 'creator']), $selectedBranchId);

        $query->when($selectedProject, fn($q) => $q->where('project_name', $selectedProject));
        $query->when($selectedStatus, fn($q) => $q->where('status', $selectedStatus));
        $query->when($request->get('search'), fn($q, $v) => $q->where(function($q) use ($v) {
            $q->where('nama_konsumen', 'like', "%{$v}%")
              ->orWhere('kav', 'like', "%{$v}%")
              ->orWhere('project_name', 'like', "%{$v}%")
              ->orWhere('nama_marketing', 'like', "%{$v}%");
        }));

        $sortField = $request->get('sort', 'tanggal');
        $sortDir = $request->get('dir', 'desc');
        $allowedSorts = ['tanggal', 'nama_konsumen', 'kav', 'project_name', 'pekerjaan', 'status_perkawinan', 'umur', 'nama_marketing', 'status'];
        if (!in_array($sortField, $allowedSorts)) $sortField = 'tanggal';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';

        $perPage = $request->get('per_page', '15');
        if ($perPage === 'all') {
            $records = $query->orderBy($sortField, $sortDir)->get();
        } else {
            $records = $query->orderBy($sortField, $sortDir)->paginate((int) $perPage)->withQueryString();
        }

        return view('crm.dana-talangan.index', compact('records', 'branches', 'projects', 'selectedBranchId', 'selectedProject', 'selectedStatus', 'sortField', 'sortDir', 'perPage'));
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

    public function store(StoreDanaTalanganRequest $request)
    {
        $user = Auth::user();
        $data = $request->validated();

        if (!$user->canViewAllBranches()) {
            $data['branch_id'] = $user->branch_id;
        }

        $data['pinjam_nama'] = $request->boolean('pinjam_nama');
        $data['created_by'] = $user->id;

        DanaTalangan::create($data);

        return redirect()->route('dana-talangan.index', array_filter($request->only(['branch_id', 'project_name', 'status'])))
            ->with('success', 'Data dana talangan berhasil ditambahkan.');
    }

    public function edit(DanaTalangan $danaTalangan)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $danaTalangan->branch_id !== $user->branch_id) {
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

    public function update(UpdateDanaTalanganRequest $request, DanaTalangan $danaTalangan)
    {
        $user = Auth::user();
        $data = $request->validated();

        $data['pinjam_nama'] = $request->boolean('pinjam_nama');
        $data['konfirmasi_keuangan'] = $request->boolean('konfirmasi_keuangan');

        $danaTalangan->update($data);

        return redirect()->route('dana-talangan.index', array_filter($request->only(['branch_id', 'project_name', 'status'])))
            ->with('success', 'Data dana talangan berhasil diperbarui.');
    }

    public function export(Request $request)
    {
        $user = Auth::user();
        $query = DanaTalangan::with(['branch', 'creator']);

        if (!$user->canViewAllBranches()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $query->when($request->get('project_name'), fn($q, $v) => $q->where('project_name', $v));
        $query->when($request->get('status'), fn($q, $v) => $q->where('status', $v));

        $records = $query->latest('tanggal')->get();

        $filename = 'dana-talangan-' . now()->format('Y-m-d') . '.xls';
        return DanaTalanganExport::toBrowser($records, $filename);
    }

    public function destroy(DanaTalangan $danaTalangan)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $danaTalangan->branch_id !== $user->branch_id) {
            abort(403);
        }

        $danaTalangan->delete();

        return redirect()->route('dana-talangan.index', array_filter(request()->only(['branch_id', 'project_name', 'status'])))
            ->with('success', 'Data dana talangan berhasil dihapus.');
    }
}
