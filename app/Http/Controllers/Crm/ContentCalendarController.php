<?php

namespace App\Http\Controllers\Crm;

use App\Exports\ContentItemExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Crm\Traits\Exportable;
use App\Http\Controllers\Crm\Traits\FilterableBranch;
use App\Http\Controllers\Crm\Traits\Importable;
use App\Http\Controllers\Crm\Traits\RedirectsShowToEdit;
use App\Http\Requests\Crm\StoreContentItemRequest;
use App\Http\Requests\Crm\UpdateContentItemRequest;
use App\Imports\ContentItemImport;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ContentCalendarController extends Controller
{
    use Exportable, FilterableBranch, Importable, RedirectsShowToEdit;

    protected string $showEditRoute = 'content-calendar.edit';

    protected string $showEditParam = 'content_calendar';

    protected string $exportClass = ContentItemExport::class;

    protected string $importView = 'crm.content-calendar.import';

    protected string $importClass = ContentItemImport::class;

    protected array $importPreservedParams = ['view', 'branch_id', 'project_name', 'item_type', 'status', 'priority', 'pic'];

    protected string $importErrorRoute = 'content-calendar.import';

    protected string $importSuccessRoute = 'content-calendar.index';

    public function index(Request $request)
    {
        $user = Auth::user();
        $viewMode = in_array($request->get('view'), ['today', 'calendar', 'tasks', 'content', 'all'], true)
            ? $request->get('view') : 'today';
        $month = (int) $request->get('month', now()->month);
        $year = (int) $request->get('year', now()->year);
        $selectedBranchId = $this->resolveSelectedBranchId($request->get('branch_id'));
        $selectedProject = $request->get('project_name');
        $selectedType = $request->get('item_type');
        $contextType = $viewMode === 'tasks' ? 'task' : ($viewMode === 'content' ? 'content' : null);
        if ($contextType) {
            $selectedType = null;
        }
        $selectedStatus = $request->get('status');
        $selectedPriority = $request->get('priority');
        $selectedPic = trim((string) $request->get('pic'));
        $search = trim((string) $request->get('search'));
        $effectiveType = $contextType ?: $selectedType;
        if ($effectiveType && $selectedStatus && ! in_array($selectedStatus, ContentItem::STATUSES[$effectiveType] ?? [], true)) {
            $selectedStatus = null;
        }
        if ($effectiveType && $effectiveType !== 'task') {
            $selectedPriority = null;
        }

        $branches = $this->resolveBranches();
        $projects = $this->resolveBranchProjects($selectedBranchId);
        $filterProjects = LeadMaster::where('is_active', true)
            ->whereNotNull('branch_id')
            ->when(! $user->canViewAllBranches(), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->orderBy('project_name')
            ->get(['project_name', 'branch_id']);
        if ($selectedProject && ! $filterProjects->contains('project_name', $selectedProject)) {
            $filterProjects->push((object) ['project_name' => $selectedProject, 'branch_id' => $selectedBranchId ?: $user->branch_id]);
        }
        $baseQuery = ContentItem::with(['branch', 'creator', 'assignees'])
            ->visibleTo($user);
        if ($selectedBranchId && $user->canViewAllBranches()) {
            $baseQuery->where('branch_id', $selectedBranchId);
        }
        $this->applyFilters($baseQuery, $selectedProject, $selectedType, $selectedStatus, $selectedPriority, $selectedPic, $search);

        $data = compact(
            'viewMode', 'contextType', 'month', 'year', 'branches', 'selectedBranchId', 'projects', 'filterProjects', 'selectedProject',
            'selectedType', 'selectedStatus', 'selectedPriority', 'selectedPic', 'search'
        );
        $data['typeCounts'] = [
            'task' => (clone $baseQuery)->where('item_type', 'task')->count(),
            'agenda' => (clone $baseQuery)->where('item_type', 'agenda')->count(),
            'content' => (clone $baseQuery)->where('item_type', 'content')->count(),
        ];

        if ($viewMode === 'today') {
            $data['agendaToday'] = (clone $baseQuery)->where('item_type', 'agenda')->whereDate('scheduled_date', today())->orderBy('start_time')->get();
            $data['tasksToday'] = (clone $baseQuery)->where('item_type', 'task')->whereDate('scheduled_date', today())->orderByDesc('priority')->get();
            $data['overdueTasks'] = (clone $baseQuery)->where('item_type', 'task')->whereDate('scheduled_date', '<', today())->whereNotIn('status', ['completed'])->orderBy('scheduled_date')->get();
            $data['contentToday'] = (clone $baseQuery)->where('item_type', 'content')->whereDate('scheduled_date', today())->orderBy('start_time')->get();
            $data['tomorrowItems'] = (clone $baseQuery)->whereDate('scheduled_date', today()->addDay())->orderBy('scheduled_date')->get();
            $data['allItemIds'] = collect([$data['agendaToday'], $data['tasksToday'], $data['overdueTasks'], $data['contentToday']])->flatten()->pluck('id')->unique()->values()->all();
        } elseif ($viewMode === 'calendar') {
            $currentMonth = Carbon::create($year, $month, 1);
            $items = (clone $baseQuery)->whereYear('scheduled_date', $year)->whereMonth('scheduled_date', $month)->orderBy('scheduled_date')->get();
            $data += [
                'calendar' => $this->calendarGrid($items, $currentMonth),
                'currentMonth' => $currentMonth,
                'prevMonth' => $currentMonth->copy()->subMonth(),
                'nextMonth' => $currentMonth->copy()->addMonth(),
                'allItemIds' => $items->pluck('id')->all(),
            ];
        } elseif ($viewMode === 'tasks') {
            $items = (clone $baseQuery)->where('item_type', 'task')->orderBy('scheduled_date')->get();
            $data['boardColumns'] = collect(ContentItem::STATUSES['task'])->mapWithKeys(fn ($status) => [$status => $items->where('status', $status)->values()]);
            $data['allItemIds'] = $items->pluck('id')->all();
        } elseif ($viewMode === 'content') {
            $items = (clone $baseQuery)->where('item_type', 'content')->orderBy('scheduled_date')->get();
            $data['boardColumns'] = collect(ContentItem::STATUSES['content'])->mapWithKeys(fn ($status) => [$status => $items->where('status', $status)->values()]);
            $data['allItemIds'] = $items->pluck('id')->all();
        } else {
            $data['items'] = (clone $baseQuery)->orderByDesc('scheduled_date')->paginate(20)->withQueryString();
            $data['allItemIds'] = $data['items']->pluck('id')->all();
        }

        return view('crm.content-calendar.index', $data);
    }

    public function create(Request $request)
    {
        return view('crm.content-calendar.create', $this->formData(null, $request->get('type', 'task')));
    }

    public function store(StoreContentItemRequest $request)
    {
        $user = Auth::user();
        $data = $request->validated();
        $assigneeIds = Arr::pull($data, 'assigned_user_ids', []);
        if (! $user->canViewAllBranches()) {
            $data['branch_id'] = $user->branch_id;
        }
        $this->validateAssignees($assigneeIds, (int) $data['branch_id']);
        $data = $this->normalizePlannerData($data);
        $data['created_by'] = $user->id;

        $item = ContentItem::create($data);
        $item->assignees()->sync($assigneeIds);

        return redirect()->route('content-calendar.index', ['view' => $this->returnView($request)])
            ->with('success', ucfirst($item->item_type).' berhasil ditambahkan.');
    }

    public function edit(ContentItem $contentItem)
    {
        $this->authorize('update', $contentItem);

        return view('crm.content-calendar.edit', $this->formData($contentItem));
    }

    public function update(UpdateContentItemRequest $request, ContentItem $contentItem)
    {
        $this->authorize('update', $contentItem);
        $data = $request->validated();
        $assigneeIds = Arr::pull($data, 'assigned_user_ids', []);
        if (! Auth::user()->canViewAllBranches()) {
            $data['branch_id'] = Auth::user()->branch_id;
        }
        $this->validateAssignees($assigneeIds, (int) $data['branch_id']);
        $contentItem->update($this->normalizePlannerData($data, $contentItem));
        $contentItem->assignees()->sync($assigneeIds);

        return redirect()->route('content-calendar.index', ['view' => $this->returnView($request)])
            ->with('success', 'Item Work Planner berhasil diperbarui.');
    }

    public function export(Request $request)
    {
        $query = ContentItem::with(['branch', 'creator', 'assignees'])->visibleTo(Auth::user());
        if ($request->filled('branch_id') && Auth::user()->canViewAllBranches()) {
            $query->where('branch_id', $request->branch_id);
        }
        $this->applyFilters($query, $request->project_name, $request->item_type, $request->status, $request->priority, (string) $request->pic, (string) $request->search);
        $records = $query->orderBy('scheduled_date')->get();

        return ContentItemExport::toBrowser($records, 'work-planner-'.now()->format('Ymd').'.xlsx');
    }

    public function detail(ContentItem $contentItem)
    {
        $this->authorize('view', $contentItem);

        return response()->json($contentItem->load(['creator', 'assignees', 'branch']));
    }

    public function destroy(ContentItem $contentItem)
    {
        $this->authorize('delete', $contentItem);
        $contentItem->delete();

        return back()->with('success', 'Item Work Planner berhasil dihapus.');
    }

    public function bulkUpdate(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'status' => ['nullable', 'string'],
            'priority' => ['nullable', 'in:low,medium,high,urgent'],
            'pic_names' => ['nullable', 'array'],
            'pic_names.*' => ['nullable', 'string', 'max:255'],
        ]);
        $items = ContentItem::visibleTo(Auth::user())->whereIn('id', $data['ids'])->get();
        if (! empty($data['status'])) {
            $types = $items->pluck('item_type')->unique();
            if ($types->count() !== 1 || ! in_array($data['status'], ContentItem::STATUSES[$types->first()] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Bulk status hanya dapat diterapkan pada item bertipe sama.']);
            }
        }
        foreach ($items as $item) {
            $updates = array_filter([
                'status' => $data['status'] ?? null,
                'priority' => $data['priority'] ?? null,
            ]);
            if (! empty($data['pic_names'])) {
                $updates['pic_names'] = array_values(array_unique(array_merge($item->pic_names ?? [], array_filter($data['pic_names']))));
            }
            if ($updates) {
                $item->update($this->normalizeCompletion($updates, $item));
            }
        }

        return back()->with('success', $items->count().' item berhasil diperbarui.');
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']])['ids'];
        $items = ContentItem::visibleTo(Auth::user())->whereIn('id', $ids)->get();
        foreach ($items as $item) {
            $this->authorize('delete', $item);
            $item->delete();
        }

        return back()->with('success', $items->count().' item berhasil dihapus.');
    }

    private function formData(?ContentItem $item = null, string $defaultType = 'task'): array
    {
        $defaultType = in_array($defaultType, ContentItem::TYPES, true) ? $defaultType : 'task';
        $user = Auth::user();
        $branches = $this->resolveBranches();
        $projects = LeadMaster::where('is_active', true)
            ->when(! $user->canViewAllBranches(), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->orderBy('project_name')->get();
        $users = User::where('is_active', true)
            ->when(! $user->canViewAllBranches(), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->orderBy('name')->get(['id', 'name', 'branch_id']);
        $item?->load('assignees');

        return compact('item', 'defaultType', 'branches', 'projects', 'users');
    }

    private function normalizePlannerData(array $data, ?ContentItem $existing = null): array
    {
        $data['scheduled_date'] = $data['item_type'] === 'agenda' ? $data['start_date'] : $data['deadline_date'];
        $data['pic_names'] = array_values(array_filter(array_map(fn ($name) => trim((string) $name), $data['pic_names'] ?? [])));
        if ($data['item_type'] !== 'agenda') {
            $data['agenda_type'] = null;
            $data['location'] = null;
            $data['start_time'] = null;
            $data['end_time'] = null;
        }
        if ($data['item_type'] !== 'content') {
            $data['content_format'] = null;
            $data['asset_url'] = null;
        }
        if ($data['item_type'] !== 'task') {
            $data['priority'] = 'medium';
        }

        return $this->normalizeCompletion($data, $existing);
    }

    private function normalizeCompletion(array $data, ?ContentItem $existing = null): array
    {
        $type = $data['item_type'] ?? $existing?->item_type ?? 'task';
        $status = $data['status'] ?? $existing?->status;
        $finished = in_array($status, match ($type) {
            'agenda' => ['done', 'cancelled'],
            'content' => ['published', 'cancelled'],
            default => ['completed'],
        }, true);
        $data['completed_at'] = $finished ? ($existing?->completed_at ?? now()) : null;

        return $data;
    }

    private function validateAssignees(array $ids, int $branchId): void
    {
        if (empty($ids)) {
            return;
        }
        $validCount = User::whereIn('id', $ids)->where('is_active', true)->where('branch_id', $branchId)->count();
        if ($validCount !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['assigned_user_ids' => 'PIC akun harus aktif dan berasal dari cabang item.']);
        }
    }

    private function applyFilters(Builder $query, $project, $type, $status, $priority, string $pic, string $search): void
    {
        $query->when($project, fn ($query) => $query->where('project_name', $project));
        $query->when($type, fn ($query) => $query->where('item_type', $type));
        $query->when($status, fn ($query) => $query->where('status', $status));
        $query->when($priority, fn ($query) => $query->where('item_type', 'task')->where('priority', $priority));
        $query->when($pic !== '', fn ($query) => $query->where(function ($query) use ($pic) {
            $query->whereRaw('LOWER(pic_names) LIKE ?', ['%'.mb_strtolower($pic).'%'])
                ->orWhereHas('assignees', fn ($users) => $users->whereRaw('LOWER(users.name) LIKE ?', ['%'.mb_strtolower($pic).'%']));
        }));
        $query->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
            $query->where('title', 'like', "%{$search}%")->orWhere('task_detail', 'like', "%{$search}%");
        }));
    }

    private function calendarGrid($items, Carbon $month): array
    {
        $byDay = $items->groupBy(fn ($item) => (int) $item->scheduled_date->day);
        $firstDay = ($month->copy()->startOfMonth()->dayOfWeek + 6) % 7;
        $calendar = [];
        $day = 1;
        for ($week = 0; $week < 6 && $day <= $month->daysInMonth; $week++) {
            $days = [];
            for ($dow = 0; $dow < 7; $dow++) {
                if (($week === 0 && $dow < $firstDay) || $day > $month->daysInMonth) {
                    $days[] = ['day' => null, 'isToday' => false, 'items' => collect()];
                } else {
                    $days[] = [
                        'day' => $day,
                        'isToday' => $day === now()->day && $month->month === now()->month && $month->year === now()->year,
                        'items' => $byDay->get($day, collect()),
                    ];
                    $day++;
                }
            }
            $calendar[] = $days;
        }

        return $calendar;
    }

    private function returnView(Request $request): string
    {
        $view = $request->input('return_view', 'today');

        return in_array($view, ['today', 'calendar', 'tasks', 'content', 'all'], true) ? $view : 'today';
    }
}
