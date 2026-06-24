<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\LeadEvent;
use App\Models\LeadMaster;
use App\Exports\LeadEventExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeadEventController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedBranchId = $request->get('branch_id');
        $selectedProjectName = $request->get('project_name');

        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();
            $query = LeadEvent::with(['branch', 'creator']);

            if ($selectedBranchId) {
                $query->where('branch_id', $selectedBranchId);
            }

            $projects = LeadMaster::where('is_active', true)
                ->when($selectedBranchId, fn($q) => $q->where('branch_id', $selectedBranchId))
                ->orderBy('project_name')
                ->get();
        } else {
            $branches = collect();
            $selectedBranchId = $user->branch_id;
            $query = LeadEvent::with(['branch', 'creator'])->where('branch_id', $selectedBranchId);

            $projects = LeadMaster::where('is_active', true)
                ->where('branch_id', $user->branch_id)
                ->orderBy('project_name')
                ->get();
        }

        if ($selectedProjectName) {
            $query->where('project_name', $selectedProjectName);
        }

        $perPage = $request->get('per_page', '15');
        if ($perPage === 'all') {
            $events = $query->latest()->get();
        } else {
            $events = $query->latest()->paginate((int) $perPage)->withQueryString();
        }

        return view('crm.lead-events.index', compact('events', 'branches', 'selectedBranchId', 'selectedProjectName', 'projects', 'perPage'));
    }

    public function create()
    {
        $user = Auth::user();
        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();
        } else {
            $branches = collect([$user->branch]);
        }

        $projects = LeadMaster::where('is_active', true)->get();
        $sources = LeadMaster::where('is_active', true)->whereNotNull('lead_source')->distinct()->orderBy('lead_source')->pluck('lead_source');

        return view('crm.lead-events.create', compact('branches', 'projects', 'sources'));
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $data = $request->validate([
            'branch_id' => $user->canViewAllBranches() ? 'required|exists:branches,id' : 'nullable',
            'project_name' => 'required|string|max:255',
            'lead_source' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'total_budget' => 'nullable|numeric|min:0',
            'status' => 'required|in:berlangsung,selesai',
            'notes' => 'nullable|string',
        ]);

        if (!$user->canViewAllBranches()) {
            $data['branch_id'] = $user->branch_id;
        }

        $data['created_by'] = $user->id;
        $event = LeadEvent::create($data);

        if (!$event->event_id) {
            $prefix = 'EV-' . now()->format('Ymd') . '-' . strtoupper(substr(preg_replace('/[^A-Z]/', '', $event->project_name), 0, 5));
            $counter = str_pad($event->id, 2, '0', STR_PAD_LEFT);
            $event->update(['event_id' => $prefix . '-' . $counter]);
        }

        return redirect()->route('lead-events.index', array_filter($request->only(['branch_id', 'project_name'])))
            ->with('success', 'Event berhasil ditambahkan.');
    }

    public function edit(LeadEvent $leadEvent)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $leadEvent->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();
        } else {
            $branches = collect([$user->branch]);
        }

        $projects = LeadMaster::where('is_active', true)->get();
        $sources = LeadMaster::where('is_active', true)->whereNotNull('lead_source')->distinct()->orderBy('lead_source')->pluck('lead_source');
        $event = $leadEvent;

        return view('crm.lead-events.edit', compact('event', 'branches', 'projects', 'sources'));
    }

    public function update(Request $request, LeadEvent $leadEvent)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $leadEvent->branch_id !== $user->branch_id) {
            abort(403);
        }

        $data = $request->validate([
            'branch_id' => $user->canViewAllBranches() ? 'required|exists:branches,id' : 'nullable',
            'project_name' => 'required|string|max:255',
            'lead_source' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'total_budget' => 'nullable|numeric|min:0',
            'status' => 'required|in:berlangsung,selesai',
            'notes' => 'nullable|string',
        ]);

        $leadEvent->update($data);

        return redirect()->route('lead-events.index', array_filter($request->only(['branch_id', 'project_name'])))
            ->with('success', 'Event berhasil diperbarui.');
    }

    public function export(Request $request)
    {
        $user = Auth::user();
        $query = LeadEvent::with(['branch', 'creator']);

        if (!$user->canViewAllBranches()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('project_name')) {
            $query->where('project_name', $request->project_name);
        }

        $records = $query->latest()->get();
        $filename = 'lead-events-' . now()->format('Ymd') . '.xlsx';

        LeadEventExport::toBrowser($records, $filename);
    }

    public function bulkDestroy(Request $request)
    {
        $ids = array_filter(explode(',', $request->input('selected_ids', '')));
        if (empty($ids)) {
            return back()->with('error', 'Tidak ada data yang dipilih.');
        }

        $user = Auth::user();
        $query = LeadEvent::whereIn('id', $ids);
        if (!$user->canViewAllBranches()) {
            $query->where('branch_id', $user->branch_id);
        }
        $count = $query->delete();

        return redirect()->route('lead-events.index', array_filter($request->only(['branch_id', 'project_name'])))
            ->with('success', "$count event berhasil dihapus.");
    }

    public function destroy(LeadEvent $leadEvent)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $leadEvent->branch_id !== $user->branch_id) {
            abort(403);
        }

        $leadEvent->delete();

        return redirect()->route('lead-events.index', array_filter(request()->only(['branch_id', 'project_name'])))
            ->with('success', 'Event berhasil dihapus.');
    }

    public function show(LeadEvent $leadEvent)
    {
        return redirect()->route('lead-events.edit', ['lead_event' => $leadEvent->id]);
    }
}
