<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\LeadDaily;
use App\Models\LeadEvent;
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

            $todayAgenda = $this->getTodayAgenda($selectedBranchId, $selectedProject);
            $overdueContent = $this->getOverdueContent($selectedBranchId, $selectedProject);
            $recentActivity = $this->getRecentActivity($selectedBranchId, $selectedProject);

            return view('crm.dashboard', compact('branches', 'projects', 'branch', 'selectedBranchId', 'selectedProject', 'totalContent', 'upcomingContent', 'branchStatuses', 'todayAgenda', 'overdueContent', 'recentActivity'));
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
        $totalPosted = (clone $baseQuery)->where('status', 'posted')->count();

        $todayAgenda = $this->getTodayAgenda($branch->id, $selectedProject);
        $overdueContent = $this->getOverdueContent($branch->id, $selectedProject);
        $recentActivity = $this->getRecentActivity($branch->id, $selectedProject);

        return view('crm.dashboard', compact('branch', 'projects', 'totalContent', 'upcomingContent', 'totalPosted', 'selectedProject', 'todayAgenda', 'overdueContent', 'recentActivity'));
    }

    private function getTodayAgenda($branchId = null, $projectName = null)
    {
        $agenda = collect();

        ContentItem::whereDate('scheduled_date', today())
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn($q) => $q->where('project_name', $projectName))
            ->get()
            ->each(fn($i) => $agenda->push([
                'label' => $i->title,
                'subtitle' => $i->platform,
                'time' => $i->scheduled_date,
                'type' => 'Konten',
                'color' => '#b3bd95',
                'status' => $i->status,
            ]));

        LeadEvent::where(function ($q) {
                $q->whereDate('start_date', '=', today())
                  ->orWhere(function ($q) {
                      $q->whereDate('start_date', '<', today())
                        ->whereDate('end_date', '>=', today());
                  });
            })
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->get()
            ->each(fn($e) => $agenda->push([
                'label' => $e->project_name,
                'subtitle' => $e->lead_source,
                'time' => $e->start_date,
                'type' => 'Event',
                'color' => '#e6915d',
                'status' => $e->status,
            ]));

        return $agenda->sortBy('time')->values();
    }

    private function getOverdueContent($branchId = null, $projectName = null)
    {
        return ContentItem::whereDate('scheduled_date', '<', today())
            ->where('status', '!=', 'posted')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn($q) => $q->where('project_name', $projectName))
            ->orderBy('scheduled_date')
            ->take(5)
            ->get();
    }

    private function getRecentActivity($branchId = null, $projectName = null)
    {
        $activity = collect();

        ContentItem::with('creator')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn($q) => $q->where('project_name', $projectName))
            ->latest()->take(5)->get()
            ->each(fn($i) => $activity->push([
                'type' => 'Konten', 'color' => '#b3bd95',
                'text' => $i->title, 'time' => $i->created_at, 'user' => $i->creator?->name ?? '-',
            ]));

        LeadDaily::with('creator', 'leadEvent')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->latest()->take(5)->get()
            ->each(fn($d) => $activity->push([
                'type' => 'Lead Harian', 'color' => '#c0392b',
                'text' => $d->leads_count . ' leads' . ($d->leadEvent ? ' untuk ' . $d->leadEvent->project_name : ''),
                'time' => $d->created_at, 'user' => $d->creator?->name ?? '-',
            ]));

        LeadEvent::with('creator')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn($q) => $q->where('project_name', $projectName))
            ->latest()->take(5)->get()
            ->each(fn($e) => $activity->push([
                'type' => 'Event', 'color' => '#e6915d',
                'text' => $e->project_name . ($e->lead_source ? ' (' . $e->lead_source . ')' : ''),
                'time' => $e->created_at, 'user' => $e->creator?->name ?? '-',
            ]));

        DanaTalangan::with('creator')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn($q) => $q->where('project_name', $projectName))
            ->latest()->take(5)->get()
            ->each(fn($d) => $activity->push([
                'type' => 'Dana Talangan', 'color' => '#f1c40f',
                'text' => $d->nama_konsumen . ($d->project_name ? ' - ' . $d->project_name : ''),
                'time' => $d->created_at, 'user' => $d->creator?->name ?? '-',
            ]));

        return $activity->sortByDesc('time')->take(10)->values();
    }
}
