<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Crm\Traits\FilterableBranch;
use App\Http\Controllers\Crm\Traits\RedirectsShowToEdit;
use App\Http\Controllers\Crm\Traits\Exportable;
use App\Http\Controllers\Crm\Traits\Importable;
use App\Http\Requests\Crm\StoreContentItemRequest;
use App\Http\Requests\Crm\UpdateContentItemRequest;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Exports\ContentItemExport;
use App\Imports\ContentItemImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ContentCalendarController extends Controller
{
    use FilterableBranch;
    use RedirectsShowToEdit;
    use Exportable;
    use Importable;

    protected string $showEditRoute = 'content-calendar.edit';
    protected string $showEditParam = 'content_calendar';

    protected string $exportClass = ContentItemExport::class;

    protected string $importView = 'crm.content-calendar.import';
    protected string $importClass = ContentItemImport::class;
    protected array $importPreservedParams = ['branch_id', 'project_name'];
    protected string $importErrorRoute = 'content-calendar.import';
    protected string $importSuccessRoute = 'content-calendar.index';

    public function index(Request $request)
    {
        $month = (int) $request->get('month', now()->month);
        $year = (int) $request->get('year', now()->year);
        $selectedBranchId = $this->resolveSelectedBranchId($request->get('branch_id'));
        $selectedProject = $request->get('project_name');

        $branches = $this->resolveBranches();
        $projects = $this->resolveBranchProjects($selectedBranchId);
        $query = $this->applyBranchScope(ContentItem::with(['branch', 'creator']), $selectedBranchId);

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
        $branches = $this->resolveBranches();
        $user = Auth::user();
        if ($user->canViewAllBranches()) {
            $projects = LeadMaster::where('is_active', true)->orderBy('project_name')->get();
        } else {
            $projects = LeadMaster::where('is_active', true)
                ->where('branch_id', $user->branch_id)
                ->orderBy('project_name')
                ->get();
        }
        return view('crm.content-calendar.create', compact('branches', 'projects'));
    }

    public function store(StoreContentItemRequest $request)
    {
        $user = Auth::user();
        $data = $request->validated();

        if (!$user->canViewAllBranches()) {
            $data['branch_id'] = $user->branch_id;
        }

        $data['created_by'] = $user->id;

        ContentItem::create($data);

        return redirect()->route('content-calendar.index', array_filter($request->only(['month', 'year', 'branch_id', 'project_name'])))->with('success', 'Konten berhasil ditambahkan.');
    }

    public function edit(ContentItem $contentItem)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $contentItem->branch_id !== $user->branch_id) {
            abort(403);
        }

        $branches = $this->resolveBranches();
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

    public function update(UpdateContentItemRequest $request, ContentItem $contentItem)
    {
        $data = $request->validated();

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
