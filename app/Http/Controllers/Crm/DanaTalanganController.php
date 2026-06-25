<?php

namespace App\Http\Controllers\Crm;

use App\Exports\DanaTalanganExport;
use App\Imports\DanaTalanganImport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Crm\Traits\FilterableBranch;
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

    public function show(DanaTalangan $danaTalangan)
    {
        return redirect()->route('dana-talangan.edit', ['dana_talangan' => $danaTalangan->id]);
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

        $filename = 'dana-talangan-' . now()->format('Y-m-d') . '.xlsx';
        DanaTalanganExport::toBrowser($records, $filename);
    }

    public function exportTemplate()
    {
        DanaTalanganExport::generateTemplate();
    }

    public function import()
    {
        return view('crm.dana-talangan.import');
    }

    public function importStore(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx']);

        $user = Auth::user();
        $branchId = $user->canViewAllBranches()
            ? $request->get('branch_id')
            : $user->branch_id;

        $result = DanaTalanganImport::import(
            $request->file('file')->getPathname(),
            $branchId,
            $request->only(['branch_id', 'project_name', 'status'])
        );

        $message = $result['imported'] . ' data berhasil diimport.';
        if (!empty($result['errors'])) {
            return redirect()->route('dana-talangan.import')
                ->with('success', $message)
                ->with('import_errors', $result['errors']);
        }

        return redirect()->route('dana-talangan.index')
            ->with('success', $message);
    }

    public function bulkDestroy(Request $request)
    {
        $ids = array_filter(explode(',', $request->input('selected_ids', '')));
        if (empty($ids)) {
            return back()->with('error', 'Tidak ada data yang dipilih.');
        }

        $query = $this->applyBranchScope(DanaTalangan::whereIn('id', $ids), null);
        $count = $query->delete();

        return redirect()->route('dana-talangan.index', array_filter($request->only(['branch_id', 'project_name', 'status'])))
            ->with('success', "$count data dana talangan berhasil dihapus.");
    }

    public function bulkUpdate(Request $request)
    {
        $ids = array_filter(explode(',', $request->input('selected_ids', '')));
        $newStatus = $request->input('new_status', 'aktif');
        if (empty($ids)) {
            return back()->with('error', 'Tidak ada data yang dipilih.');
        }

        $query = $this->applyBranchScope(DanaTalangan::whereIn('id', $ids), null);
        $count = $query->update(['status' => $newStatus]);

        return redirect()->route('dana-talangan.index', array_filter($request->only(['branch_id', 'project_name', 'status'])))
            ->with('success', "$count data dana talangan berhasil diperbarui.");
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
