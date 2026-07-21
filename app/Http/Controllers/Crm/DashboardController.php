<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\DatabaseSheetRecord;
use App\Models\DatabaseSheetSyncStatus;
use App\Models\KonsumenProgressSheetRow;
use App\Models\LeadMaster;
use App\Services\KonsumenProgressSyncService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function __construct(private readonly WorkspaceAccessService $workspaceAccess) {}

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

        $projects = LeadMaster::where('is_active', true)
            ->when($selectedBranchId, fn ($query) => $query->where('branch_id', $selectedBranchId))
            ->orderBy('project_name')
            ->get();

        $recentActivity = $this->getRecentActivity($user, $selectedBranchId, $selectedProject);
        $leadStats = $this->getLeadStats($selectedBranchId, $selectedProject);
        $danaStats = $this->getDanaTalanganStats($selectedBranchId, $selectedProject);
        $actionQueue = $this->getActionQueue($user, $selectedBranchId, $selectedProject);
        $syncHealth = $this->getSyncHealth($selectedBranchId);
        $dashboardSyncStatus = $selectedBranchId ? DatabaseSheetSyncStatus::where('branch_id', $selectedBranchId)->first() : null;
        $canSyncDatabase = $branch && $this->workspaceAccess->canSyncBranch($user, $branch);
        $konsumenProgress = $this->getKonsumenProgress($selectedBranchId);

        return view('crm.dashboard', compact('branches', 'branch', 'selectedBranchId', 'projects', 'selectedProject', 'recentActivity', 'leadStats', 'danaStats', 'actionQueue', 'syncHealth', 'konsumenProgress', 'dashboardSyncStatus', 'canSyncDatabase'));
    }

    private function getRecentActivity($user, $branchId = null, $projectName = null)
    {
        $activity = collect();

        ContentItem::with('creator')->visibleTo($user)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn ($q) => $q->where('project_name', $projectName))
            ->latest()->take(5)->get()
            ->each(fn ($i) => $activity->push([
                'type' => 'Task', 'color' => '#b3bd95',
                'text' => $i->title, 'time' => $i->created_at, 'user' => $i->creator?->name ?? '-',
            ]));

        DatabaseSheetRecord::whereRaw('LOWER(sheet_name) = ?', ['lead'])
            ->whereNull('oasis_deleted_at')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn ($q) => $q->where('row_data->proyek', $projectName))
            ->latest()->take(5)->get()
            ->each(fn ($r) => $activity->push([
                'type' => 'Lead', 'color' => '#e6915d',
                'text' => ($r->row_data['nama_konsumen'] ?? '-').' ('.($r->row_data['id_lead'] ?? '#'.$r->id).')',
                'time' => $r->created_at, 'user' => '-',
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

    private function getLeadStats($branchId = null, $projectName = null): array
    {
        $query = DatabaseSheetRecord::whereRaw('LOWER(sheet_name) = ?', ['lead'])
            ->whereNull('oasis_deleted_at')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        if ($projectName) {
            $query->where('row_data->proyek', $projectName);
        }

        $today = now()->format('Y-m-d');
        $startOfMonth = now()->startOfMonth()->format('Y-m-d');
        $endOfMonth = now()->endOfMonth()->format('Y-m-d');

        $leadToday = (clone $query)
            ->where('row_data->tanggal_lead', $today)
            ->count();

        $leadThisMonth = (clone $query)
            ->where('row_data->tanggal_lead', '>=', $startOfMonth)
            ->where('row_data->tanggal_lead', '<=', $endOfMonth)
            ->count();

        $sourceRecords = (clone $query)->get(['row_data']);
        $sourceCounts = [];
        foreach ($sourceRecords as $r) {
            $source = $r->row_data['sumber'] ?? null;
            if ($source) {
                $sourceCounts[$source] = ($sourceCounts[$source] ?? 0) + 1;
            }
        }
        arsort($sourceCounts);
        $topSource = array_key_first($sourceCounts) ?: '—';

        $latestLeads = (clone $query)
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($r) => [
                'nama' => $r->row_data['nama_konsumen'] ?? '-',
                'source' => $r->row_data['sumber'] ?? '-',
                'tanggal' => $r->row_data['tanggal_lead'] ?? '-',
                'project' => $r->row_data['proyek'] ?? '-',
            ]);

        return compact('leadToday', 'leadThisMonth', 'topSource', 'latestLeads');
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
            ->where('status', '!=', 'completed')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn ($q) => $q->where('project_name', $projectName))
            ->orderBy('deadline_date')
            ->take(5)
            ->get()
            ->each(fn ($t) => $queue->push([
                'text' => 'Task overdue: '.$t->title,
                'urgency' => 3,
                'type' => 'task_overdue',
                'link' => route('content-calendar.index'),
                'time' => $t->deadline_date,
            ]));

        ContentItem::visibleTo($user)->whereDate('scheduled_date', today())
            ->where('status', '!=', 'completed')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn ($q) => $q->where('project_name', $projectName))
            ->orderBy('scheduled_date')
            ->take(5)
            ->get()
            ->each(fn ($t) => $queue->push([
                'text' => 'Task hari ini: '.$t->title,
                'urgency' => 4,
                'type' => 'task_today',
                'link' => route('content-calendar.index'),
                'time' => $t->scheduled_date,
            ]));

        DatabaseSheetRecord::whereRaw('LOWER(sheet_name) = ?', ['lead'])
            ->whereNull('oasis_deleted_at')
            ->where('row_data->tanggal_lead', today()->format('Y-m-d'))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($projectName, fn ($q) => $q->where('row_data->proyek', $projectName))
            ->latest()
            ->take(5)
            ->get()
            ->each(fn ($r) => $queue->push([
                'text' => 'Lead baru: '.($r->row_data['nama_konsumen'] ?? '-'),
                'urgency' => 5,
                'type' => 'lead_today',
                'link' => route('database.index', ['sheet' => 'lead']),
                'time' => $r->created_at,
            ]));

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
