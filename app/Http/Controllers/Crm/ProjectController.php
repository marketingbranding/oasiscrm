<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreProjectRequest;
use App\Http\Requests\Crm\UpdateProjectRequest;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Services\ProjectAdministrationService;
use App\Services\SalesLeadSheetOptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $this->ensureSuperadmin();
        $selectedBranchId = $request->get('branch_id');
        $branches = Branch::where('is_active', true)->forDropdown()->get();
        $query = LeadMaster::with('branch')->withCount('kavlings');

        if ($selectedBranchId) {
            $query->where('branch_id', $selectedBranchId);
        }
        $query->when($request->get('search'), fn ($q, $v) => $q->where(function ($q) use ($v) {
            $q->where('project_name', 'like', "%{$v}%");
        }));

        $sortField = $request->get('sort', 'created_at');
        $sortDir = $request->get('dir', 'desc');
        $allowedSorts = ['created_at', 'project_name', 'kavlings_count', 'is_active'];
        if (! in_array($sortField, $allowedSorts)) {
            $sortField = 'created_at';
        }
        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        $perPage = $request->get('per_page', '15');
        if ($perPage === 'all') {
            $projects = $query->orderBy($sortField, $sortDir)->get();
        } else {
            $projects = $query->orderBy($sortField, $sortDir)->paginate((int) $perPage)->withQueryString();
        }

        return view('crm.projects.index', compact('projects', 'branches', 'selectedBranchId', 'sortField', 'sortDir', 'perPage'));
    }

    public function create()
    {
        $this->ensureSuperadmin();
        $branches = Branch::where('is_active', true)->forDropdown()->get();

        return view('crm.projects.create', compact('branches'));
    }

    public function store(StoreProjectRequest $request, ProjectAdministrationService $projects)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active', true);

        $projects->create($data);

        return redirect()->route('projects.index', array_filter($request->only(['branch_id'])))
            ->with('success', 'Proyek berhasil ditambahkan.');
    }

    public function edit(LeadMaster $project, SalesLeadSheetOptionService $sheetOptions)
    {
        $this->ensureSuperadmin();
        $branches = Branch::where('is_active', true)->forDropdown()->get();
        $projectOptions = [];
        $sheetOptionsWarning = null;
        if ($project->branch && filled($project->branch->sheet_id)) {
            try {
                $projectOptions = $sheetOptions->forBranch($project->branch)['project'];
            } catch (Throwable) {
                $sheetOptionsWarning = 'Opsi proyek spreadsheet sedang tidak tersedia. Identitas spreadsheet saat ini tetap dipertahankan.';
                if (filled($project->sheet_project_name)) {
                    $projectOptions = [$project->sheet_project_name];
                }
            }
        }

        return view('crm.projects.edit', compact('project', 'branches', 'projectOptions', 'sheetOptionsWarning'));
    }

    public function update(UpdateProjectRequest $request, LeadMaster $project, ProjectAdministrationService $projects)
    {
        $result = $projects->update($request, $project, $request->validated(), $request->user());
        if ($result instanceof Response) {
            return $result;
        }

        return redirect()->route('projects.index', array_filter($request->only(['branch_id'])))
            ->with('success', 'Proyek berhasil diperbarui.');
    }

    public function destroy(Request $request, LeadMaster $project, ProjectAdministrationService $projects)
    {
        $this->ensureSuperadmin();
        $archived = $projects->archive($request, $project, $request->user(), $request->input('expected_updated_at'));
        if ($archived instanceof Response) {
            return $archived;
        }

        return redirect()->route('projects.index', array_filter($request->only(['branch_id'])))
            ->with($archived ? 'success' : 'warning', $archived ? 'Proyek berhasil dinonaktifkan.' : 'Proyek sudah nonaktif.');
    }

    public function show(LeadMaster $project)
    {
        return redirect()->route('projects.edit', ['project' => $project->id]);
    }

    private function ensureSuperadmin(): void
    {
        if (! Auth::user()->isSuperadmin()) {
            abort(403);
        }
    }
}
