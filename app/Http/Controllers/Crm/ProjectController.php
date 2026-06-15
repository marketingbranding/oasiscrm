<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\LeadMaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $this->ensureSuperadmin();
        $selectedBranchId = $request->get('branch_id');
        $branches = Branch::where('is_active', true)->get();
        $query = LeadMaster::with('branch');

        if ($selectedBranchId) {
            $query->where('branch_id', $selectedBranchId);
        }

        $projects = $query->latest()->get();

        return view('crm.projects.index', compact('projects', 'branches', 'selectedBranchId'));
    }

    public function create()
    {
        $this->ensureSuperadmin();
        $branches = Branch::where('is_active', true)->get();
        return view('crm.projects.create', compact('branches'));
    }

    public function store(Request $request)
    {
        $this->ensureSuperadmin();
        $data = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'project_name' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        LeadMaster::create($data);

        return redirect()->route('projects.index', array_filter($request->only(['branch_id'])))
            ->with('success', 'Proyek berhasil ditambahkan.');
    }

    public function edit(LeadMaster $project)
    {
        $this->ensureSuperadmin();
        $branches = Branch::where('is_active', true)->get();
        return view('crm.projects.edit', compact('project', 'branches'));
    }

    public function update(Request $request, LeadMaster $project)
    {
        $this->ensureSuperadmin();
        $data = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'project_name' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        $project->update($data);

        return redirect()->route('projects.index', array_filter($request->only(['branch_id'])))
            ->with('success', 'Proyek berhasil diperbarui.');
    }

    public function destroy(LeadMaster $project)
    {
        $this->ensureSuperadmin();
        $project->delete();

        return redirect()->route('projects.index', array_filter(request()->only(['branch_id'])))
            ->with('success', 'Proyek berhasil dihapus.');
    }

    public function show(LeadMaster $project)
    {
        return redirect()->route('projects.edit', ['project' => $project->id]);
    }

    private function ensureSuperadmin(): void
    {
        if (!Auth::user()->isSuperadmin()) {
            abort(403);
        }
    }
}
