<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ContentItem;
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

        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();
            $query = ContentItem::with(['branch', 'creator']);

            if ($selectedBranchId) {
                $query->where('branch_id', $selectedBranchId);
            }
        } else {
            $branches = collect();
            $selectedBranchId = $user->branch_id;
            $query = ContentItem::with(['branch', 'creator'])->where('branch_id', $selectedBranchId);
        }

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

        return view('crm.content-calendar.index', compact('calendar', 'currentMonth', 'prevMonth', 'nextMonth', 'branches', 'selectedBranchId'));
    }

    public function create()
    {
        $user = Auth::user();
        if ($user->canViewAllBranches()) {
            $branches = Branch::where('is_active', true)->get();
        } else {
            $branches = collect([$user->branch]);
        }
        return view('crm.content-calendar.create', compact('branches'));
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'branch_id' => $user->canViewAllBranches() ? 'required|exists:branches,id' : 'nullable',
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

        return         redirect()->route('content-calendar.index', array_filter($request->only(['month', 'year', 'branch_id'])))->with('success', 'Konten berhasil ditambahkan.');
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
        return view('crm.content-calendar.edit', compact('content', 'branches'));
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
            'platform' => 'nullable|string|max:50',
            'scheduled_date' => 'required|date',
            'status' => 'required|in:draft,review,approved,posted',
            'notes' => 'nullable|string',
        ]);

        $contentItem->update($data);
        return redirect()->route('content-calendar.index', array_filter($request->only(['month', 'year', 'branch_id'])))->with('success', 'Konten berhasil diperbarui.');
    }

    public function destroy(ContentItem $contentItem)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $contentItem->branch_id !== $user->branch_id) {
            abort(403);
        }

        $contentItem->delete();
        return redirect()->route('content-calendar.index', array_filter(request()->only(['month', 'year', 'branch_id'])))->with('success', 'Konten berhasil dihapus.');
    }
}
