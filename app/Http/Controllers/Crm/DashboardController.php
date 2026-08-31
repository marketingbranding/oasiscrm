<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\DatabaseSheetSyncStatus;
use App\Models\KonsumenProgressSheetRow;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Services\DashboardConsumerMetricsService;
use App\Services\KonsumenProgressSyncService;
use App\Services\OrganizationScopeService;
use App\Services\SalesWeeklyMetricsService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function __construct(
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly OrganizationScopeService $organizationScope,
        private readonly SalesWeeklyMetricsService $weeklyMetrics,
        private readonly DashboardConsumerMetricsService $consumerMetrics,
    ) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedBranchId = $request->get('branch_id');
        $selectedProject = $request->get('project_name');

        $branches = $this->workspaceAccess->accessibleBranches($user);
        if ($selectedBranchId) {
            $branch = $this->workspaceAccess->resolveRequestedBranch($user, $selectedBranchId);
            abort_unless($branch, 403);
        } elseif ($user->isSuperadmin()) {
            $branch = null;
        } else {
            $branch = $this->workspaceAccess->resolveRequestedBranch($user, null);
            $selectedBranchId = $branch?->id;
        }

        if (! $branch) {
            if (! $user->isSuperadmin()) {
                return view('crm.dashboard', compact('branches', 'branch', 'selectedBranchId'))->with('error', 'Anda belum memiliki akses cabang.');
            }
        }

        $projects = $user->isSales()
            ? $this->workspaceAccess->accessibleProjects($user)->when(
                $selectedBranchId,
                fn ($projects) => $projects->where('branch_id', $selectedBranchId)->values(),
            )
            : LeadMaster::where('is_active', true)
                ->when($selectedBranchId, fn ($query) => $query->where('branch_id', $selectedBranchId))
                ->orderBy('project_name')
                ->get();

        $recentActivity = $this->getRecentActivity($user, $selectedBranchId, $selectedProject);
        $leadStats = $this->getLeadStats($user, $selectedBranchId, $selectedProject);
        $danaStats = $this->getDanaTalanganStats($selectedBranchId, $selectedProject);
        $actionQueue = $this->getActionQueue($user, $selectedBranchId, $selectedProject);
        $syncHealth = $this->getSyncHealth($selectedBranchId);
        $dashboardSyncStatus = $selectedBranchId ? DatabaseSheetSyncStatus::where('branch_id', $selectedBranchId)->first() : null;
        $canSyncDatabase = $user->hasPermission('database.sync') && $branch && $this->workspaceAccess->canSyncBranch($user, $branch);
        $konsumenProgress = $this->getKonsumenProgress($selectedBranchId);
        $localConsumerMetrics = $this->consumerMetrics->local($selectedBranchId, $branches->pluck('id')->all());
        if ($localConsumerMetrics !== null) {
            $konsumenProgress = $localConsumerMetrics['metrics'];
        }
        $salesWeekly = null;
        $salesReminders = null;
        if ($user->isSales()) {
            $salesProjectId = $selectedProject ? $projects->firstWhere('project_name', $selectedProject)?->id : null;
            $salesFilters = array_filter(['branch_id' => $selectedBranchId, 'project_id' => $salesProjectId, 'sales_user_id' => $user->id]);
            $salesWeekly = $this->weeklyMetrics->metrics($user, $this->weeklyMetrics->period(), $salesFilters);
            $salesReminders = $this->weeklyMetrics->reminders($user, $salesFilters);
        }

        return view('crm.dashboard', compact('branches', 'branch', 'selectedBranchId', 'projects', 'selectedProject', 'recentActivity', 'leadStats', 'danaStats', 'actionQueue', 'syncHealth', 'konsumenProgress', 'dashboardSyncStatus', 'canSyncDatabase', 'salesWeekly', 'salesReminders'));
    }

    private function getRecentActivity($user, $branchId = null, $projectName = null)
    {
        $activity = collect();

        ContentItem::with('creator')->visibleTo($user)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn ($q) => $q->where('project_name', $projectName))
            ->latest()->take(5)->get()
            ->each(fn ($i) => $activity->push([
                'type' => match ($i->item_type) {
                    'agenda' => 'Agenda', 'content' => 'Konten', default => 'Task'
                }, 'color' => '#b3bd95',
                'text' => $i->title, 'time' => $i->created_at, 'user' => $i->creator?->name ?? '-',
            ]));

        $this->databaseLeadQuery($user, $branchId, $projectName)
            ->latest()->take(5)->get()
            ->each(fn ($lead) => $activity->push([
                'type' => 'Lead', 'color' => '#e6915d',
                'text' => $lead->customer_name.' ('.($lead->external_lead_id ?? '#'.$lead->id).')',
                'time' => $lead->created_at, 'user' => '-',
            ]));

        DanaTalangan::with('creator')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn ($q) => $q->where('project_name', $projectName))
            ->latest()->take(5)->get()
            ->each(fn ($d) => $activity->push([
                'type' => 'Dana Talangan', 'color' => '#f1c40f',
                'text' => $d->nama_konsumen.($d->project_name ? ' - '.$d->project_name : ''),
                'time' => $d->created_at, 'user' => $d->creator?->name ?? '-',
            ]));

        return $activity->sortByDesc('time')->take(10)->values();
    }

    private function getLeadStats($user, $branchId = null, $projectName = null): array
    {
        $query = $this->databaseLeadQuery($user, $branchId, $projectName);
        $today = now()->toDateString();
        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        $leadToday = (clone $query)->whereDate('lead_date', $today)->count();
        $leadThisMonth = (clone $query)
            ->whereDate('lead_date', '>=', $startOfMonth)
            ->whereDate('lead_date', '<=', $endOfMonth)
            ->count();

        $sourceCounts = (clone $query)->with('leadSource')->get()->map->effective_source->filter()->countBy();
        $topSource = $sourceCounts->sortDesc()->keys()->first() ?: '—';

        $latestLeads = (clone $query)
            ->with(['leadSource', 'project'])
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($lead) => [
                'nama' => $lead->customer_name,
                'source' => $lead->effective_source ?: '-',
                'tanggal' => $lead->lead_date?->toDateString() ?? '-',
                'project' => $lead->project?->project_name ?? '-',
            ]);

        return compact('leadToday', 'leadThisMonth', 'topSource', 'latestLeads');
    }

    private function databaseLeadQuery($user, $branchId = null, $projectName = null)
    {
        $query = SalesLead::query();
        if (! $user->hasPermission('database.view') || ! $user->hasScopedPermission('database')) {
            return $query->whereKey([]);
        }

        return $query
            ->whereIn('branch_id', $this->organizationScope->branchIds($user, 'database'))
            ->whereIn('project_id', $this->organizationScope->projectIds($user, 'database'))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->when($projectName, fn ($query) => $query->whereHas('project', fn ($project) => $project->where('project_name', $projectName)));
    }

    private function getDanaTalanganStats($branchId = null, $projectName = null): array
    {
        $query = DanaTalangan::when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn ($q) => $q->where('project_name', $projectName));

        $tidakSanggup = (clone $query)->where('status', 'tidak_sanggup')->count();
        $belumKonfirmasi = (clone $query)->where('konfirmasi_keuangan', false)->count();
        $hariIni = (clone $query)->whereDate('tgl_komitmen', today())->count();
        $overdue = (clone $query)
            ->whereDate('tgl_komitmen', '<', today())
            ->where('status', '!=', 'lunas')
            ->count();

        return compact('tidakSanggup', 'belumKonfirmasi', 'hariIni', 'overdue');
    }

    private function getActionQueue($user, $branchId = null, $projectName = null)
    {
        $queue = collect();

        DanaTalangan::whereDate('tgl_komitmen', '<', today())
            ->where('status', '!=', 'lunas')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn ($q) => $q->where('project_name', $projectName))
            ->latest('tgl_komitmen')
            ->take(5)
            ->get()
            ->each(fn ($d) => $queue->push([
                'text' => 'Dana Talangan: '.$d->nama_konsumen.($d->project_name ? ' ('.$d->project_name.')' : ''),
                'urgency' => 1,
                'type' => 'dana_overdue',
                'link' => route('dana-talangan.index'),
                'time' => $d->tgl_komitmen,
            ]));

        DanaTalangan::where('konfirmasi_keuangan', false)
            ->where('status', '!=', 'lunas')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn ($q) => $q->where('project_name', $projectName))
            ->latest()
            ->take(5)
            ->get()
            ->each(fn ($d) => $queue->push([
                'text' => 'Konfirmasi Dana: '.$d->nama_konsumen.($d->project_name ? ' ('.$d->project_name.')' : ''),
                'urgency' => 2,
                'type' => 'dana_confirm',
                'link' => route('dana-talangan.index'),
                'time' => $d->created_at,
            ]));

        ContentItem::visibleTo($user)->whereDate('deadline_date', '<', today())
            ->whereNotIn('status', ['completed', 'done', 'cancelled', 'rescheduled', 'uploaded'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn ($q) => $q->where('project_name', $projectName))
            ->orderBy('deadline_date')
            ->take(5)
            ->get()
            ->each(fn ($t) => $queue->push([
                'text' => ($t->item_type === 'agenda' ? 'Agenda' : 'Task').' overdue: '.$t->title,
                'urgency' => 3,
                'type' => $t->item_type === 'agenda' ? 'agenda_overdue' : 'task_overdue',
                'link' => route('content-calendar.index'),
                'time' => $t->deadline_date,
            ]));

        ContentItem::visibleTo($user)->whereDate('scheduled_date', today())
            ->whereNotIn('status', ['completed', 'done', 'cancelled', 'rescheduled', 'uploaded'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn ($q) => $q->where('project_name', $projectName))
            ->orderBy('scheduled_date')
            ->take(5)
            ->get()
            ->each(fn ($t) => $queue->push([
                'text' => ($t->item_type === 'agenda' ? 'Agenda' : 'Task').' hari ini: '.$t->title,
                'urgency' => 4,
                'type' => $t->item_type === 'agenda' ? 'agenda_today' : 'task_today',
                'link' => route('content-calendar.index'),
                'time' => $t->scheduled_date,
            ]));

        if ($user->hasScopedPermission('sales_pocketbook')) {
            SalesLead::query()->visibleTo($user)
                ->whereDate('lead_date', today())
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->when($projectName, fn ($query) => $query->whereHas('project', fn ($project) => $project->where('project_name', $projectName)))
                ->latest()
                ->take(5)
                ->get()
                ->each(fn ($lead) => $queue->push([
                    'text' => 'Lead baru: '.$lead->customer_name,
                    'urgency' => 5,
                    'type' => 'lead_today',
                    'link' => route('sales-pocketbook.index'),
                    'time' => $lead->created_at,
                ]));
        }

        return $queue->sortBy('urgency')->take(10)->values();
    }

    private function getSyncHealth($branchId = null): ?array
    {
        if (! $branchId) {
            return null;
        }

        $syncStatus = DatabaseSheetSyncStatus::where('branch_id', $branchId)->first();
        if (! $syncStatus) {
            return ['status' => 'never', 'message' => 'Belum pernah sync', 'isStale' => true];
        }

        $isStale = $syncStatus->status !== 'success' || ! $syncStatus->finished_at
            || $syncStatus->finished_at->lt(now()->subMinutes((int) config('services.google_sheets.cache_stale_minutes', 30)));

        return [
            'status' => $syncStatus->status,
            'message' => $syncStatus->message,
            'finished_at' => $syncStatus->finished_at,
            'isStale' => $isStale,
            'summary' => $syncStatus->summary,
        ];
    }

    private function getKonsumenProgress($branchId = null): array
    {
        $stages = KonsumenProgressSyncService::STAGES;

        $query = KonsumenProgressSheetRow::query()
            ->whereIn('sheet_name', array_keys($stages));

        if ($branchId) {
            $query->where('branch_id', $branchId);
        } else {
            $query->whereIn('branch_id', Branch::where('is_active', true)->pluck('id'));
        }

        $rows = $query->get(['branch_id', 'sheet_name', 'row_data']);

        $rowsBySheet = [];
        foreach ($rows as $row) {
            $rowsBySheet[$row->sheet_name][] = ['branch_id' => $row->branch_id, 'row_data' => $row->row_data];
        }

        $seen = [];
        $pipeline = [];

        foreach (array_reverse(array_keys($stages)) as $stageKey) {
            $count = 0;
            foreach (($rowsBySheet[$stageKey] ?? []) as $entry) {
                $kavling = trim($entry['row_data']['id_kavling'] ?? '');
                $key = $entry['branch_id'].'|'.$kavling;
                if ($kavling === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $count++;
            }
            $pipeline[$stageKey] = [
                'label' => $stages[$stageKey],
                'count' => $count,
            ];
        }

        foreach (array_keys($stages) as $stageKey) {
            $pipeline[$stageKey] ??= [
                'label' => $stages[$stageKey],
                'count' => 0,
            ];
        }

        return $pipeline;
    }
}
