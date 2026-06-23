<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Exports\ContentItemExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ContentCalendarController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $month = (int) $request->get('month', now()->month);
        $year = (int) $request->get('year', now()->year);
        $selectedBranchId = $request->get('branch_id');
        $selectedProject = $request->get('project_name');

        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();
            $projects = LeadMaster::where('is_active', true)
                ->when($selectedBranchId, fn($q) => $q->where('branch_id', $selectedBranchId))
                ->orderBy('project_name')->get();
            $query = ContentItem::with(['branch', 'creator']);

            if ($selectedBranchId) {
                $query->where('branch_id', $selectedBranchId);
            } elseif ($user->hasRole('pusat') && $user->branch_id) {
                $selectedBranchId = $user->branch_id;
                $query->where('branch_id', $selectedBranchId);
            }
        } else {
            $branches = collect();
            $selectedBranchId = $user->branch_id;
            $projects = LeadMaster::where('is_active', true)
                ->where('branch_id', $selectedBranchId)
                ->orderBy('project_name')
                ->get();
            $query = ContentItem::with(['branch', 'creator'])->where('branch_id', $selectedBranchId);
        }

        $query->when($selectedProject, fn($q) => $q->where('project_name', $selectedProject));

        $contentItems = $query->whereYear('scheduled_date', $year)
            ->whereMonth('scheduled_date', $month)
            ->orderBy('scheduled_date')
            ->get();

        $currentMonth = now()->setYear($year)->setMonth($month);
        $prevMonth = (clone $currentMonth)->subMonth();
        $nextMonth = (clone $currentMonth)->addMonth();

        $itemsByDay = $contentItems->groupBy(fn($item) => (int)$item->scheduled_date->day);
        $daysInMonth = $currentMonth->daysInMonth;
        $firstDayOfWeek = ($currentMonth->copy()->startOfMonth()->dayOfWeek + 6) % 7;

        $calendar = [];
        $dayCounter = 1;

        for ($week = 0; $week < 6 && $dayCounter <= $daysInMonth; $week++) {
            $weekDays = [];
            for ($dow = 0; $dow < 7; $dow++) {
                if (($week === 0 && $dow < $firstDayOfWeek) || $dayCounter > $daysInMonth) {
                    $weekDays[] = ['day' => null, 'isToday' => false, 'items' => collect()];
                } else {
                    $dayNum = $dayCounter;
                    $weekDays[] = [
                        'day' => $dayNum,
                        'isToday' => $dayNum === now()->day && $month === now()->month && $year === now()->year,
                        'items' => $itemsByDay->get($dayNum, collect()),
                    ];
                    $dayCounter++;
                }
            }
            $calendar[] = $weekDays;
        }

        return view('crm.content-calendar.index', compact('calendar', 'currentMonth', 'prevMonth', 'nextMonth', 'branches', 'selectedBranchId', 'projects', 'selectedProject'));
    }

    public function create()
    {
        $user = Auth::user();
        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();
            $projects = LeadMaster::where('is_active', true)->orderBy('project_name')->get();
        } else {
            $branches = collect([$user->branch]);
            $projects = LeadMaster::where('is_active', true)
                ->where('branch_id', $user->branch_id)
                ->orderBy('project_name')
                ->get();
        }
        return view('crm.content-calendar.create', compact('branches', 'projects'));
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'branch_id' => $user->canViewAllBranches() ? 'required|exists:branches,id' : 'nullable',
            'project_name' => 'nullable|string|max:255',
            'platform' => 'nullable|string|max:50',
            'scheduled_date' => 'required|date',
            'status' => 'required|in:draft,review,approved,posted',
            'notes' => 'nullable|string',
        ]);

        if (!$user->canViewAllBranches()) {
            $data['branch_id'] = $user->branch_id;
        }

        $data['created_by'] = $user->id;

        ContentItem::create($data);

        return         redirect()->route('content-calendar.index', array_filter($request->only(['month', 'year', 'branch_id', 'project_name'])))->with('success', 'Konten berhasil ditambahkan.');
    }

    public function show(ContentItem $contentItem)
    {
        return redirect()->route('content-calendar.edit', ['content_calendar' => $contentItem->id]);
    }

    public function edit(ContentItem $contentItem)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $contentItem->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();
        } else {
            $branches = collect([$user->branch]);
        }

        $content = $contentItem;
        if ($user->canViewAllBranches()) {
            $projects = LeadMaster::where('is_active', true)->orderBy('project_name')->get();
        } else {
            $projects = LeadMaster::where('is_active', true)
                ->where('branch_id', $user->branch_id)
                ->orderBy('project_name')
                ->get();
        }
        return view('crm.content-calendar.edit', compact('content', 'branches', 'projects'));
    }

    public function update(Request $request, ContentItem $contentItem)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $contentItem->branch_id !== $user->branch_id) {
            abort(403);
        }

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'branch_id' => $user->canViewAllBranches() ? 'required|exists:branches,id' : 'nullable',
            'project_name' => 'nullable|string|max:255',
            'platform' => 'nullable|string|max:50',
            'scheduled_date' => 'required|date',
            'status' => 'required|in:draft,review,approved,posted',
            'notes' => 'nullable|string',
        ]);

        $contentItem->update($data);
        return redirect()->route('content-calendar.index', array_filter($request->only(['month', 'year', 'branch_id', 'project_name'])))->with('success', 'Konten berhasil diperbarui.');
    }

    public function export(Request $request)
    {
        $user = Auth::user();
        $month = (int) $request->get('month', now()->month);
        $year = (int) $request->get('year', now()->year);
        $query = ContentItem::with(['branch', 'creator']);

        if (!$user->canViewAllBranches()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('project_name')) {
            $query->where('project_name', $request->project_name);
        }

        $records = $query->whereYear('scheduled_date', $year)
            ->whereMonth('scheduled_date', $month)
            ->orderBy('scheduled_date')
            ->get();

        $filename = 'content-calendar-' . now()->format('Ymd') . '.xlsx';

        ContentItemExport::toBrowser($records, $filename);
    }

    public function destroy(ContentItem $contentItem)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $contentItem->branch_id !== $user->branch_id) {
            abort(403);
        }

        $contentItem->delete();
        return redirect()->route('content-calendar.index', array_filter(request()->only(['month', 'year', 'branch_id', 'project_name'])))->with('success', 'Konten berhasil dihapus.');
    }
}
