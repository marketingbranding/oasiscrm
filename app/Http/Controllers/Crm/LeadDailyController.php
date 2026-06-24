<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\LeadDaily;
use App\Models\LeadEvent;
use App\Models\LeadMaster;
use App\Exports\LeadDailyExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeadDailyController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedBranchId = $request->get('branch_id');
        $selectedEventId = $request->get('lead_event_id');
        $selectedProjectName = $request->get('project_name');

        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();
            $events = LeadEvent::with('branch')->latest()->get();
            $query = LeadDaily::with(['leadEvent', 'branch', 'creator']);

            if ($selectedBranchId) {
                $query->where('branch_id', $selectedBranchId);
            }
            if ($selectedEventId) {
                $query->where('lead_event_id', $selectedEventId);
            }

            $projects = LeadMaster::where('is_active', true)
                ->when($selectedBranchId, fn($q) => $q->where('branch_id', $selectedBranchId))
                ->orderBy('project_name')
                ->get();
        } else {
            $branches = collect();
            $selectedBranchId = $user->branch_id;
            $events = LeadEvent::with('branch')->where('branch_id', $selectedBranchId)->latest()->get();
            $query = LeadDaily::with(['leadEvent', 'branch', 'creator'])
                ->where('branch_id', $selectedBranchId);
            if ($selectedEventId) {
                $query->where('lead_event_id', $selectedEventId);
            }

            $projects = LeadMaster::where('is_active', true)
                ->where('branch_id', $user->branch_id)
                ->orderBy('project_name')
                ->get();
        }

        if ($selectedProjectName) {
            $query->whereHas('leadEvent', fn($q) => $q->where('project_name', $selectedProjectName));
            $events = $events->filter(fn($e) => $e->project_name === $selectedProjectName);
        }

        $sortField = $request->get('sort', 'date');
        $sortDir = $request->get('dir', 'desc');
        $allowedSorts = ['date', 'day_number', 'leads_count', 'cumulative_leads', 'achievement_pct'];
        if (!in_array($sortField, $allowedSorts)) $sortField = 'date';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';

        $dailyLogs = $query->orderBy($sortField, $sortDir)->get();

        return view('crm.lead-daily.index', compact('dailyLogs', 'events', 'branches', 'selectedBranchId', 'selectedEventId', 'selectedProjectName', 'projects', 'sortField', 'sortDir'));
    }

    public function create()
    {
        $user = Auth::user();
        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();
            $events = LeadEvent::with('branch')->where('status', 'berlangsung')->latest()->get();
            $projects = LeadMaster::where('is_active', true)->orderBy('project_name')->get();
        } else {
            $branches = collect([$user->branch]);
            $events = LeadEvent::with('branch')
                ->where('branch_id', $user->branch_id)
                ->where('status', 'berlangsung')
                ->latest()
                ->get();
            $projects = LeadMaster::where('is_active', true)
                ->where('branch_id', $user->branch_id)
                ->orderBy('project_name')
                ->get();
        }

        return view('crm.lead-daily.create', compact('branches', 'events', 'projects'));
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $data = $request->validate([
            'lead_event_id' => 'required|exists:lead_events,id',
            'branch_id' => 'required|exists:branches,id',
            'date' => 'required|date',
            'leads_count' => 'required|integer|min:0',
        ]);

        $event = LeadEvent::findOrFail($data['lead_event_id']);

        if (!$user->canViewAllBranches() && $event->branch_id !== $user->branch_id) {
            abort(403);
        }

        $data['branch_id'] = $event->branch_id;
        $data['created_by'] = $user->id;

        $data['day_number'] = $event->start_date->diffInDays(now()->parse($data['date'])) + 1;

        $data['cumulative_leads'] = LeadDaily::where('lead_event_id', $event->id)
            ->where('date', '<=', $data['date'])
            ->sum('leads_count') + $data['leads_count'];

        if ($event->daily_target && $event->daily_target > 0) {
            $data['achievement_pct'] = round(($data['leads_count'] / $event->daily_target) * 100, 2);
        }

        LeadDaily::create($data);

        return redirect()->route('lead-daily.index', array_filter($request->only(['branch_id', 'lead_event_id', 'project_name'])))
            ->with('success', 'Data harian berhasil ditambahkan.');
    }

    public function edit(LeadDaily $leadDaily)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $leadDaily->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($user->canViewAllBranches()) {
            $events = LeadEvent::with('branch')->latest()->get();
        } else {
            $events = LeadEvent::with('branch')->where('branch_id', $user->branch_id)->latest()->get();
        }

        $daily = $leadDaily;
        return view('crm.lead-daily.edit', compact('daily', 'events'));
    }

    public function update(Request $request, LeadDaily $leadDaily)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $leadDaily->branch_id !== $user->branch_id) {
            abort(403);
        }

        $data = $request->validate([
            'date' => 'required|date',
            'leads_count' => 'required|integer|min:0',
        ]);

        $event = $leadDaily->leadEvent;
        $data['day_number'] = $event->start_date->diffInDays(now()->parse($data['date'])) + 1;

        $data['cumulative_leads'] = LeadDaily::where('lead_event_id', $leadDaily->lead_event_id)
            ->where('date', '<=', $data['date'])
            ->where('id', '!=', $leadDaily->id)
            ->sum('leads_count') + $data['leads_count'];

        if ($event->daily_target && $event->daily_target > 0) {
            $data['achievement_pct'] = round(($data['leads_count'] / $event->daily_target) * 100, 2);
        } else {
            $data['achievement_pct'] = null;
        }

        $leadDaily->update($data);

        return redirect()->route('lead-daily.index', array_filter($request->only(['branch_id', 'lead_event_id', 'project_name'])))
            ->with('success', 'Data harian berhasil diperbarui.');
    }

    public function export(Request $request)
    {
        $user = Auth::user();
        $query = LeadDaily::with(['leadEvent', 'branch', 'creator']);

        if (!$user->canViewAllBranches()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('lead_event_id')) {
            $query->where('lead_event_id', $request->lead_event_id);
        }

        if ($request->filled('project_name')) {
            $query->whereHas('leadEvent', fn($q) => $q->where('project_name', $request->project_name));
        }

        $records = $query->latest('date')->get();
        $filename = 'lead-harian-' . now()->format('Ymd') . '.xlsx';

        LeadDailyExport::toBrowser($records, $filename);
    }

    public function bulkDestroy(Request $request)
    {
        $ids = array_filter(explode(',', $request->input('selected_ids', '')));
        if (empty($ids)) {
            return back()->with('error', 'Tidak ada data yang dipilih.');
        }

        $user = Auth::user();
        $query = LeadDaily::whereIn('id', $ids);
        if (!$user->canViewAllBranches()) {
            $query->where('branch_id', $user->branch_id);
        }
        $count = $query->delete();

        return redirect()->route('lead-daily.index', array_filter($request->only(['branch_id', 'lead_event_id', 'project_name'])))
            ->with('success', "$count data harian berhasil dihapus.");
    }

    public function destroy(LeadDaily $leadDaily)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $leadDaily->branch_id !== $user->branch_id) {
            abort(403);
        }

        $leadDaily->delete();

        return redirect()->route('lead-daily.index', array_filter(request()->only(['branch_id', 'lead_event_id', 'project_name'])))
            ->with('success', 'Data harian berhasil dihapus.');
    }
}
