<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Services\ConsumerReadComparisonService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsumerComparisonController extends Controller
{
    public function __construct(private readonly ConsumerReadComparisonService $comparison) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        $branches = Branch::query()->where('is_active', true)->orderBy('name')->get();
        $selectedBranch = $request->filled('branch_id')
            ? $branches->firstWhere('id', $request->integer('branch_id'))
            : null;
        abort_if($request->filled('branch_id') && ! $selectedBranch, 403);

        $projects = LeadMaster::query()->where('is_active', true)
            ->when($selectedBranch, fn ($query) => $query->where('branch_id', $selectedBranch->id))
            ->orderBy('project_name')->get();
        abort_if($request->filled('project_id') && ! $selectedBranch, 403);
        $selectedProject = $request->filled('project_id')
            ? $projects->firstWhere('id', $request->integer('project_id'))
            : null;
        abort_if($request->filled('project_id') && ! $selectedProject, 403);

        $result = null;
        if ($selectedBranch && $selectedProject) {
            $result = $this->comparison->compare($selectedBranch, $selectedProject);
        }

        return view('crm.consumer-comparison.index', compact('branches', 'projects', 'selectedBranch', 'selectedProject', 'result'));
    }
}
