<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\Lead;
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
            $totalPosted = (clone $baseQuery)->where('status', 'completed')->count();
            $completionRate = $totalContent > 0 ? round($totalPosted / $totalContent * 100) : 0;

            $upcomingContent = (clone $baseQuery)
                ->where('scheduled_date', '>=', now()->today())
                ->where('status', '!=', 'completed')
                ->orderBy('scheduled_date')
                ->take(5)
                ->get();

            $overdueCount = (clone $baseQuery)->whereDate('deadline_date', '<', now())->where('status', '!=', 'completed')->count();
            $upcomingWeek = (clone $baseQuery)
                ->whereDate('deadline_date', '>=', now())
                ->whereDate('deadline_date', '<=', now()->addDays(7))
                ->where('status', '!=', 'completed')
                ->orderBy('deadline_date')
                ->get();
            $upcomingWeekCount = $upcomingWeek->count();
            $topPics = $this->getTopPics($selectedBranchId, $selectedProject);

            $branchStatuses = Branch::withCount(['contentItems'])->where('is_active', true)->get()->map(function ($b) use ($selectedProject) {
                $q = ContentItem::where('branch_id', $b->id);
                if ($selectedProject) { $q->where('project_name', $selectedProject); }
                $b->posted_count = (clone $q)->where('status', 'completed')->count();
                $b->completion_rate = $b->content_items_count > 0 ? round($b->posted_count / $b->content_items_count * 100) : 0;
                return $b;
            });

            $todayAgenda = $this->getTodayAgenda($selectedBranchId, $selectedProject);
            $overdueContent = $this->getOverdueContent($selectedBranchId, $selectedProject);
            $recentActivity = $this->getRecentActivity($selectedBranchId, $selectedProject);

            return view('crm.dashboard', compact('branches', 'projects', 'branch', 'selectedBranchId', 'selectedProject', 'totalContent', 'totalPosted', 'completionRate', 'upcomingContent', 'upcomingWeek', 'branchStatuses', 'todayAgenda', 'overdueContent', 'recentActivity', 'overdueCount', 'upcomingWeekCount', 'topPics'));
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
        $totalPosted = (clone $baseQuery)->where('status', 'completed')->count();
        $completionRate = $totalContent > 0 ? round($totalPosted / $totalContent * 100) : 0;
        $upcomingContent = (clone $baseQuery)->where('scheduled_date', '>=', now()->today())->where('status', '!=', 'completed')->orderBy('scheduled_date')->take(5)->get();
        $overdueCount = (clone $baseQuery)->whereDate('deadline_date', '<', now())->where('status', '!=', 'completed')->count();
        $upcomingWeek = (clone $baseQuery)
            ->whereDate('deadline_date', '>=', now())
            ->whereDate('deadline_date', '<=', now()->addDays(7))
            ->where('status', '!=', 'completed')
            ->orderBy('deadline_date')
            ->get();
        $upcomingWeekCount = $upcomingWeek->count();
        $topPics = $this->getTopPics($branch->id, $selectedProject);

        $todayAgenda = $this->getTodayAgenda($branch->id, $selectedProject);
        $overdueContent = $this->getOverdueContent($branch->id, $selectedProject);
        $recentActivity = $this->getRecentActivity($branch->id, $selectedProject);

        return view('crm.dashboard', compact('branch', 'projects', 'totalContent', 'totalPosted', 'completionRate', 'upcomingContent', 'upcomingWeek', 'selectedProject', 'todayAgenda', 'overdueContent', 'recentActivity', 'overdueCount', 'upcomingWeekCount', 'topPics'));
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
                'type' => 'Task',
                'color' => '#b3bd95',
                'status' => $i->status,
            ]));

        Lead::whereDate('tanggal_lead', today())
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->get()
            ->each(fn($l) => $agenda->push([
                'label' => $l->nama_konsumen,
                'subtitle' => $l->proyek . ' — ' . $l->sumber,
                'time' => $l->tanggal_lead,
                'type' => 'Lead',
                'color' => '#e6915d',
                'status' => $l->status_lead,
            ]));

        return $agenda->sortBy('time')->values();
    }

    private function getOverdueContent($branchId = null, $projectName = null)
    {
        return ContentItem::whereDate('deadline_date', '<', today())
            ->where('status', '!=', 'completed')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn($q) => $q->where('project_name', $projectName))
            ->orderBy('deadline_date')
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
                'type' => 'Task', 'color' => '#b3bd95',
                'text' => $i->title, 'time' => $i->created_at, 'user' => $i->creator?->name ?? '-',
            ]));

        Lead::with('creator')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn($q) => $q->where('proyek', $projectName))
            ->latest()->take(5)->get()
            ->each(fn($l) => $activity->push([
                'type' => 'Lead', 'color' => '#e6915d',
                'text' => $l->nama_konsumen . ' (' . $l->id_lead . ')',
                'time' => $l->created_at, 'user' => $l->creator?->name ?? '-',
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

    private function getTopPics($branchId = null, $projectName = null): array
    {
        $tasks = ContentItem::where('status', '!=', 'completed')
            ->whereNotNull('pic_names')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn($q) => $q->where('project_name', $projectName))
            ->get(['pic_names']);

        $counts = [];
        foreach ($tasks as $task) {
            if (is_array($task->pic_names)) {
                foreach ($task->pic_names as $name) {
                    $name = trim((string) $name);
                    if ($name !== '') {
                        $counts[$name] = ($counts[$name] ?? 0) + 1;
                    }
                }
            }
        }

        arsort($counts);
        return array_slice($counts, 0, 5);
    }
}
