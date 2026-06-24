<?php

namespace App\Http\Controllers\Crm;

use App\Exports\DanaTalanganExport;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DanaTalangan;
use App\Models\Kavling;
use App\Models\LeadMaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DanaTalanganController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedBranchId = $request->get('branch_id');
        $selectedProject = $request->get('project_name');
        $selectedStatus = $request->get('status');

        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();
            $projects = LeadMaster::where('is_active', true)
                ->when($selectedBranchId, fn($q) => $q->where('branch_id', $selectedBranchId))
                ->orderBy('project_name')
                ->get();
            $query = DanaTalangan::with(['branch', 'creator']);

            if ($selectedBranchId) {
                $query->where('branch_id', $selectedBranchId);
            } elseif ($user->hasRole('pusat') && $user->branch_id) {
                $selectedBranchId = $user->branch_id;
                $query->where('branch_id', $selectedBranchId);
            }
        } else {
            $branches = collect();
            $selectedBranchId = $user->branch_id;
            $projects = LeadMaster::where('is_active', true)
                ->where('branch_id', $selectedBranchId)
                ->orderBy('project_name')
                ->get();
            $query = DanaTalangan::with(['branch', 'creator'])->where('branch_id', $selectedBranchId);
        }

        $query->when($selectedProject, fn($q) => $q->where('project_name', $selectedProject));
        $query->when($selectedStatus, fn($q) => $q->where('status', $selectedStatus));

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
        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();
            $projects = LeadMaster::where('is_active', true)->orderBy('project_name')->get();
        } else {
            $branches = collect([$user->branch]);
            $projects = LeadMaster::where('is_active', true)
                ->where('branch_id', $user->branch_id)
                ->orderBy('project_name')
                ->get();
        }
        $kavlings = Kavling::with('project')->orderBy('kavling_code')->get();
        return view('crm.dana-talangan.create', compact('branches', 'projects', 'kavlings'));
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $data = $request->validate([
            'tanggal' => 'required|date',
            'nama_konsumen' => 'required|string|max:255',
            'kav' => 'nullable|string|max:100',
            'branch_id' => $user->canViewAllBranches() ? 'required|exists:branches,id' : 'nullable',
            'project_name' => 'nullable|string|max:255',
            'pinjam_nama' => 'nullable|boolean',
            'pekerjaan' => 'nullable|string|max:255',
            'status_perkawinan' => 'nullable|string|max:100',
            'umur' => 'nullable|integer|min:0|max:150',
            'nama_marketing' => 'nullable|string|max:255',
            'penyelesaian' => 'nullable|string',
        ]);

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

        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();
            $projects = LeadMaster::where('is_active', true)->orderBy('project_name')->get();
        } else {
            $branches = collect([$user->branch]);
            $projects = LeadMaster::where('is_active', true)
                ->where('branch_id', $user->branch_id)
                ->orderBy('project_name')
                ->get();
        }

        $record = $danaTalangan;
        $kavlings = Kavling::with('project')->orderBy('kavling_code')->get();
        return view('crm.dana-talangan.edit', compact('record', 'branches', 'projects', 'kavlings'));
    }

    public function update(Request $request, DanaTalangan $danaTalangan)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $danaTalangan->branch_id !== $user->branch_id) {
            abort(403);
        }

        $data = $request->validate([
            'tanggal' => 'required|date',
            'nama_konsumen' => 'required|string|max:255',
            'kav' => 'nullable|string|max:100',
            'branch_id' => $user->canViewAllBranches() ? 'required|exists:branches,id' : 'nullable',
            'project_name' => 'nullable|string|max:255',
            'pinjam_nama' => 'nullable|boolean',
            'pekerjaan' => 'nullable|string|max:255',
            'status_perkawinan' => 'nullable|string|max:100',
            'umur' => 'nullable|integer|min:0|max:150',
            'nama_marketing' => 'nullable|string|max:255',
            'penyelesaian' => 'nullable|string',
            'konfirmasi_keuangan' => 'nullable|boolean',
            'status' => 'required|in:aktif,lunas',
        ]);

        $data['pinjam_nama'] = $request->boolean('pinjam_nama');
        $data['konfirmasi_keuangan'] = $request->boolean('konfirmasi_keuangan');

        $danaTalangan->update($data);

        return redirect()->route('dana-talangan.index', array_filter($request->only(['branch_id', 'project_name', 'status'])))
            ->with('success', 'Data dana talangan berhasil diperbarui.');
    }

    public function export(Request $request)
    {
        $user = Auth::user();

        if ($user->canViewAllBranches()) {
            $query = DanaTalangan::with(['branch', 'creator']);
            if ($selectedBranchId = $request->get('branch_id')) {
                $query->where('branch_id', $selectedBranchId);
            }
        } else {
            $query = DanaTalangan::where('branch_id', $user->branch_id);
        }

        $query->when($request->get('project_name'), fn($q, $v) => $q->where('project_name', $v));
        $query->when($request->get('status'), fn($q, $v) => $q->where('status', $v));

        $records = $query->latest('tanggal')->get();

        $filename = 'dana-talangan-' . now()->format('Y-m-d') . '.xlsx';
        DanaTalanganExport::toBrowser($records, $filename);
    }

    public function bulkDestroy(Request $request)
    {
        $ids = array_filter(explode(',', $request->input('selected_ids', '')));
        if (empty($ids)) {
            return back()->with('error', 'Tidak ada data yang dipilih.');
        }

        $user = Auth::user();
        $query = DanaTalangan::whereIn('id', $ids);
        if (!$user->canViewAllBranches()) {
            $query->where('branch_id', $user->branch_id);
        }
        $count = $query->delete();

        return redirect()->route('dana-talangan.index', array_filter($request->only(['branch_id', 'project_name', 'status'])))
            ->with('success', "$count data dana talangan berhasil dihapus.");
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
