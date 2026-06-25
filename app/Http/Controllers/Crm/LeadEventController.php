<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Crm\Traits\FilterableBranch;
use App\Http\Requests\Crm\StoreLeadEventRequest;
use App\Http\Requests\Crm\UpdateLeadEventRequest;
use App\Models\Branch;
use App\Models\LeadEvent;
use App\Models\LeadMaster;
use App\Models\LeadSource;
use App\Exports\LeadEventExport;
use App\Imports\LeadEventImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeadEventController extends Controller
{
    use FilterableBranch;

    public function index(Request $request)
    {
        $selectedBranchId = $this->resolveSelectedBranchId($request->get('branch_id'));
        $selectedProjectName = $request->get('project_name');

        $branches = $this->resolveBranches();
        $projects = $this->resolveBranchProjects($selectedBranchId);
        $query = $this->applyBranchScope(LeadEvent::with(['branch', 'creator']), $selectedBranchId);

        if ($selectedProjectName) {
            $query->where('project_name', $selectedProjectName);
        }
        $query->when($request->get('search'), fn($q, $v) => $q->where(function($q) use ($v) {
            $q->where('project_name', 'like', "%{$v}%")
              ->orWhere('lead_source', 'like', "%{$v}%")
              ->orWhere('location', 'like', "%{$v}%");
        }));

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
        $branches = $this->resolveBranches();
        $projects = LeadMaster::where('is_active', true)->get();
        $sources = LeadSource::where('is_active', true)->orderBy('name')->pluck('name');

        return view('crm.lead-events.create', compact('branches', 'projects', 'sources'));
    }

    public function store(StoreLeadEventRequest $request)
    {
        $user = Auth::user();
        $data = $request->validated();

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

        $branches = $this->resolveBranches();
        $projects = LeadMaster::where('is_active', true)->get();
        $sources = LeadSource::where('is_active', true)->orderBy('name')->pluck('name');
        $event = $leadEvent;

        return view('crm.lead-events.edit', compact('event', 'branches', 'projects', 'sources'));
    }

    public function update(UpdateLeadEventRequest $request, LeadEvent $leadEvent)
    {
        $data = $request->validated();

        $leadEvent->update($data);

        return redirect()->route('lead-events.index', array_filter($request->only(['branch_id', 'project_name'])))
            ->with('success', 'Event berhasil diperbarui.');
    }

    public function exportTemplate()
    {
        LeadEventExport::generateTemplate();
    }

    public function import()
    {
        return view('crm.lead-events.import');
    }

    public function importStore(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx']);

        $user = Auth::user();
        $branchId = $user->canViewAllBranches()
            ? $request->get('branch_id')
            : $user->branch_id;

        $result = LeadEventImport::import(
            $request->file('file')->getPathname(),
            $branchId,
            $request->only(['branch_id', 'project_name'])
        );

        $message = $result['imported'] . ' data berhasil diimport.';
        if (!empty($result['errors'])) {
            return redirect()->route('lead-events.import')
                ->with('success', $message)
                ->with('import_errors', $result['errors']);
        }

        return redirect()->route('lead-events.index')
            ->with('success', $message);
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

        $query = $this->applyBranchScope(LeadEvent::whereIn('id', $ids), null);
        $count = $query->delete();

        return redirect()->route('lead-events.index', array_filter($request->only(['branch_id', 'project_name'])))
            ->with('success', "$count event berhasil dihapus.");
    }

    public function bulkUpdate(Request $request)
    {
        $ids = array_filter(explode(',', $request->input('selected_ids', '')));
        $newStatus = $request->input('new_status', 'berlangsung');
        if (empty($ids)) {
            return back()->with('error', 'Tidak ada data yang dipilih.');
        }

        $allowedStatus = ['berlangsung', 'selesai'];
        if (!in_array($newStatus, $allowedStatus)) {
            $newStatus = 'berlangsung';
        }

        $query = $this->applyBranchScope(LeadEvent::whereIn('id', $ids), null);
        $count = $query->update(['status' => $newStatus]);

        return redirect()->route('lead-events.index', array_filter($request->only(['branch_id', 'project_name'])))
            ->with('success', "$count event berhasil diperbarui.");
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
