<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedBranchId = $request->get('branch_id');
        $selectedProject = $request->get('project_name');

        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();
            $projects = LeadMaster::where('is_active', true)
                ->when($selectedBranchId, fn($q) => $q->where('branch_id', $selectedBranchId))
                ->orderBy('project_name')->get();

            if ($selectedBranchId) {
                $branch = Branch::findOrFail($selectedBranchId);
            } elseif ($user->hasRole('pusat') && $user->branch_id) {
                $selectedBranchId = $user->branch_id;
                $branch = Branch::findOrFail($selectedBranchId);
            } else {
                $branch = null;
            }

            $baseQuery = ContentItem::query()
                ->when($selectedBranchId, fn($q) => $q->where('branch_id', $selectedBranchId))
                ->when($selectedProject, fn($q) => $q->where('project_name', $selectedProject));

            $totalContent = (clone $baseQuery)->count();

            $upcomingContent = (clone $baseQuery)
                ->where('scheduled_date', '>=', now()->today())
                ->orderBy('scheduled_date')
                ->take(5)
                ->get();

            $branchStatuses = Branch::withCount(['contentItems'])->where('is_active', true)->get()->map(function ($b) use ($selectedProject) {
                $q = ContentItem::where('branch_id', $b->id);
                if ($selectedProject) { $q->where('project_name', $selectedProject); }
                $b->posted_count = (clone $q)->where('status', 'posted')->count();
                return $b;
            });

            $recentUpdates = ContentItem::with(['branch', 'creator'])
                ->when($selectedBranchId, fn($q) => $q->where('branch_id', $selectedBranchId))
                ->when($selectedProject, fn($q) => $q->where('project_name', $selectedProject))
                ->latest()
                ->take(5)
                ->get();

            return view('crm.dashboard', compact('branches', 'projects', 'branch', 'selectedBranchId', 'selectedProject', 'totalContent', 'upcomingContent', 'branchStatuses', 'recentUpdates'));
        }

        $branch = $user->branch;
        if (!$branch) {
            $branches = Branch::where('is_active', true)->get();
            return view('crm.dashboard', compact('branches', 'branch', 'selectedBranchId'))->with('error', 'Anda belum memiliki cabang.');
        }

        $projects = LeadMaster::where('is_active', true)
            ->where('branch_id', $branch->id)
            ->orderBy('project_name')
            ->get();

        $baseQuery = ContentItem::where('branch_id', $branch->id)
            ->when($selectedProject, fn($q) => $q->where('project_name', $selectedProject));

        $totalContent = (clone $baseQuery)->count();
        $upcomingContent = (clone $baseQuery)->where('scheduled_date', '>=', now()->today())->orderBy('scheduled_date')->take(5)->get();
        $recentUpdates = (clone $baseQuery)->with('creator')->latest()->take(5)->get();
        $totalPosted = (clone $baseQuery)->where('status', 'posted')->count();

        return view('crm.dashboard', compact('branch', 'projects', 'totalContent', 'upcomingContent', 'recentUpdates', 'totalPosted', 'selectedProject'));
    }
}
