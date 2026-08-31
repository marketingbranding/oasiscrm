<?php

namespace App\Http\Controllers\Crm;

use App\Exports\DanaTalanganExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Crm\Traits\BulkOperations;
use App\Http\Controllers\Crm\Traits\Exportable;
use App\Http\Controllers\Crm\Traits\FilterableBranch;
use App\Http\Controllers\Crm\Traits\Importable;
use App\Http\Controllers\Crm\Traits\RedirectsShowToEdit;
use App\Http\Requests\Crm\StoreDanaTalanganRequest;
use App\Http\Requests\Crm\UpdateDanaTalanganRequest;
use App\Imports\DanaTalanganImport;
use App\Models\Branch;
use App\Models\DanaTalangan;
use App\Models\DanaTalanganBridgeSetting;
use App\Models\DanaTalanganReconciliationItem;
use App\Models\DanaTalanganSyncStatus;
use App\Models\Kavling;
use App\Models\LeadMaster;
use App\Services\CollaborationNotificationService;
use App\Services\DanaTalanganBridgeService;
use App\Services\DanaTalanganOptionService;
use App\Services\DanaTalanganService;
use App\Services\OptimisticLockService;
use App\Services\OrganizationScopeService;
use App\Services\PresenceService;
use App\Services\SyncResponseService;
use App\Services\WorkspaceAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DanaTalanganController extends Controller
{
    use BulkOperations;
    use Exportable;
    use FilterableBranch;
    use Importable { importStore as private traitImportStore; }
    use RedirectsShowToEdit;

    protected string $showEditRoute = 'dana-talangan.edit';

    protected string $showEditParam = 'dana_talangan';

    protected string $exportClass = DanaTalanganExport::class;

    protected string $importView = 'crm.dana-talangan.import';

    protected string $importClass = DanaTalanganImport::class;

    protected array $importPreservedParams = ['branch_id', 'project_name', 'status'];

    protected string $importErrorRoute = 'dana-talangan.import';

    protected string $importSuccessRoute = 'dana-talangan.index';

    protected string $bulkModel = DanaTalangan::class;

    protected string $bulkLabel = 'data dana talangan';

    protected array $bulkStatusOptions = ['sanggup', 'tidak_sanggup', 'lunas'];

    protected string $bulkDefaultStatus = 'sanggup';

    protected string $bulkRedirectRoute = 'dana-talangan.index';

    protected array $bulkRedirectParams = ['branch_id', 'project_name', 'status', 'search', 'filter_mode', 'date_from', 'date_to', 'month_from', 'month_to'];

    public function __construct(
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly OptimisticLockService $optimisticLock,
        private readonly CollaborationNotificationService $notifications,
        private readonly PresenceService $presence,
        private readonly SyncResponseService $syncResponses,
        private readonly OrganizationScopeService $organizationScope,
    ) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        $allowedBranchIds = $this->organizationScope->branchIds($user, 'bridge_fund');
        $selectedBranchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;
        abort_if($selectedBranchId && ! in_array($selectedBranchId, $allowedBranchIds, true), 403);
        $selectedProject = $request->get('project_name');
        $selectedStatus = $request->get('status');
        [$filterMode, $dateFrom, $dateTo, $monthFrom, $monthTo, $rangeStart, $rangeEnd] = $this->resolveDateRange($request);

        $branches = Branch::where('is_active', true)->whereIn('id', $allowedBranchIds)->orderBy('name')->get();
        $projects = LeadMaster::where('is_active', true)->whereIn('branch_id', $allowedBranchIds)
            ->when($selectedBranchId, fn ($query) => $query->where('branch_id', $selectedBranchId))->orderBy('project_name')->get();
        $formProjects = LeadMaster::where('is_active', true)
            ->whereNotNull('branch_id')
            ->whereIn('branch_id', $allowedBranchIds)
            ->orderBy('project_name')
            ->get(['id', 'project_name', 'branch_id']);
        $syncedProjectNames = DanaTalangan::query()->whereIn('branch_id', $allowedBranchIds)
            ->when($selectedBranchId, fn ($query) => $query->where('branch_id', $selectedBranchId))
            ->whereNotNull('project_name')
            ->distinct()
            ->pluck('project_name');
        $projectOptions = $projects->pluck('project_name')->merge($syncedProjectNames)->filter()->unique()->sort()->values();
        $query = DanaTalangan::with(['branch', 'creator'])->whereIn('branch_id', $allowedBranchIds)
            ->withCount('comments')
            ->when($selectedBranchId, fn ($query) => $query->where('branch_id', $selectedBranchId));

        if ($rangeStart) {
            $query->whereDate('tanggal', '>=', $rangeStart);
        }
        if ($rangeEnd) {
            $query->whereDate('tanggal', '<=', $rangeEnd);
        }

        $query->when($selectedProject, fn ($q) => $q->where('project_name', $selectedProject));
        $query->when($selectedStatus, fn ($q) => $q->where('status', $selectedStatus));
        $search = trim((string) $request->get('search'));
        $query->when($search !== '', fn ($q) => $q->whereRaw('LOWER(nama_konsumen) LIKE ?', ['%'.mb_strtolower($search).'%']));

        $statusCounts = DanaTalangan::query()->whereIn('branch_id', $allowedBranchIds)
            ->when($selectedBranchId, fn ($q) => $q->where('branch_id', $selectedBranchId))
            ->when($rangeStart, fn ($q) => $q->whereDate('tanggal', '>=', $rangeStart))
            ->when($rangeEnd, fn ($q) => $q->whereDate('tanggal', '<=', $rangeEnd))
            ->when($selectedProject, fn ($q) => $q->where('project_name', $selectedProject))
            ->when($selectedStatus, fn ($q) => $q->where('status', $selectedStatus))
            ->when($search !== '', fn ($q) => $q->whereRaw('LOWER(nama_konsumen) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $summary = [
            'total' => (int) $statusCounts->sum(),
            'sanggup' => (int) ($statusCounts['sanggup'] ?? 0),
            'tidak_sanggup' => (int) ($statusCounts['tidak_sanggup'] ?? 0),
            'lunas' => (int) ($statusCounts['lunas'] ?? 0),
        ];

        $trackingSummary = collect();
        if ($search !== '') {
            $trackingRecords = DanaTalangan::query()->whereIn('branch_id', $allowedBranchIds)
                ->when($selectedBranchId, fn ($query) => $query->where('branch_id', $selectedBranchId))
                ->whereRaw('LOWER(nama_konsumen) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orderBy('tanggal')
                ->get(['nama_konsumen', 'tanggal']);
            $trackingSummary = $trackingRecords
                ->groupBy(fn ($record) => mb_strtolower(preg_replace('/\s+/', ' ', trim($record->nama_konsumen))))
                ->map(function ($group) use ($rangeStart, $rangeEnd) {
                    $withinRange = $group->filter(function ($record) use ($rangeStart, $rangeEnd) {
                        $date = $record->tanggal->format('Y-m-d');

                        return (! $rangeStart || $date >= $rangeStart) && (! $rangeEnd || $date <= $rangeEnd);
                    })->count();

                    return [
                        'name' => $group->first()->nama_konsumen,
                        'total' => $group->count(),
                        'within_range' => $withinRange,
                    ];
                })->values();
        }

        $sortField = $request->get('sort', 'tanggal');
        $sortDir = $request->get('dir', 'desc');
        $allowedSorts = ['tanggal', 'nama_konsumen', 'kav', 'project_name', 'pekerjaan', 'status_perkawinan', 'umur', 'nama_marketing', 'tgl_komitmen', 'status'];
        if (! in_array($sortField, $allowedSorts)) {
            $sortField = 'tanggal';
        }
        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        $perPage = $request->get('per_page', '15');
        if ($perPage === 'all') {
            $records = $query->orderBy($sortField, $sortDir)->get();
        } else {
            $records = $query->orderBy($sortField, $sortDir)->paginate((int) $perPage)->withQueryString();
        }

        $spreadsheetId = config('services.google_sheets.dana_talangan_spreadsheet_id');
        $syncStatus = DanaTalanganSyncStatus::where('spreadsheet_id', $spreadsheetId)->first();
        $bridgeSetting = DanaTalanganBridgeSetting::where('spreadsheet_id', $spreadsheetId)->first();
        $canReview = $user->hasPermission('bridge_fund.manage_all');
        $canSync = $canReview && config('services.google_sheets.dana_talangan_bridge_enabled') && $bridgeSetting?->mode?->pullEnabled();
        $canManage = $user->hasPermission('bridge_fund.manage');
        $canExport = $user->hasPermission('bridge_fund.export');
        $openReconciliationCount = $canReview ? DanaTalanganReconciliationItem::where('status', 'open')->count() : 0;

        return view('crm.dana-talangan.index', compact('records', 'branches', 'projects', 'formProjects', 'projectOptions', 'selectedBranchId', 'selectedProject', 'selectedStatus', 'sortField', 'sortDir', 'perPage', 'syncStatus', 'bridgeSetting', 'search', 'trackingSummary', 'filterMode', 'dateFrom', 'dateTo', 'monthFrom', 'monthTo', 'canSync', 'canManage', 'canExport', 'canReview', 'openReconciliationCount', 'summary'));
    }

    public function create()
    {
        $user = Auth::user();
        $allowedBranchIds = $this->organizationScope->branchIds($user, 'bridge_fund', 'manage');
        $branches = Branch::where('is_active', true)->whereIn('id', $allowedBranchIds)->orderBy('name')->get();

        $projects = LeadMaster::where('is_active', true)
            ->whereIn('branch_id', $allowedBranchIds)
            ->orderBy('project_name')->get();

        $accessibleBranchIds = $allowedBranchIds;
        $kavlings = Kavling::with('project')
            ->whereHas('project', fn ($query) => $query->whereIn('branch_id', $accessibleBranchIds))
            ->orderBy('kavling_code')->get();

        return view('crm.dana-talangan.create', compact('branches', 'projects', 'kavlings'));
    }

    public function store(StoreDanaTalanganRequest $request, DanaTalanganService $service, DanaTalanganOptionService $optionService)
    {
        $user = Auth::user();
        $data = $request->validated();
        $data['branch_id'] ??= $this->workspaceAccess->resolveRequestedBranch($user, null)?->id;
        $project = $service->resolveProject($data['project_name'], (int) $data['branch_id']);
        if ($project === null) {
            return back()->withInput()->withErrors(['project_name' => 'Proyek tidak terdaftar tepat pada cabang yang dipilih.']);
        }
        $data['project_id'] = $project->id;
        $data['project_name'] = $project->project_name;
        $branch = Branch::findOrFail($project->branch_id);
        abort_unless(in_array((int) $branch->id, $this->organizationScope->branchIds($user, 'bridge_fund', 'manage'), true), 403);
        abort_unless($this->workspaceAccess->canEditBranch($user, $branch), 403);
        if (! $optionService->isValidKavling($branch, $data['project_name'], $data['kav'] ?? null)) {
            return back()->withInput()->withErrors(['kav' => 'Kav tidak terdaftar pada Proyek yang dipilih.']);
        }

        $data['pinjam_nama'] = $request->boolean('pinjam_nama');
        $data['konfirmasi_keuangan'] = $request->boolean('konfirmasi_keuangan');
        $service->create($data, $user);

        return redirect()->route('dana-talangan.index')
            ->with(config('services.google_sheets.dana_talangan_bridge_enabled') && app(DanaTalanganBridgeModeService::class)->isPushEnabled() ? 'success' : 'warning', config('services.google_sheets.dana_talangan_bridge_enabled') && app(DanaTalanganBridgeModeService::class)->isPushEnabled() ? 'Data dana talangan berhasil ditambahkan.' : 'Data dana talangan tersimpan lokal; bridge belum aktif.');
    }

    public function edit(DanaTalangan $danaTalangan)
    {
        $user = Auth::user();
        abort_unless(in_array((int) $danaTalangan->branch_id, $this->organizationScope->branchIds($user, 'bridge_fund', 'manage'), true), 403);
        abort_unless($this->workspaceAccess->canEditBranch($user, $danaTalangan->branch_id), 403);

        $allowedBranchIds = $this->organizationScope->branchIds($user, 'bridge_fund', 'manage');
        $branches = Branch::where('is_active', true)->whereIn('id', $allowedBranchIds)->orderBy('name')->get();
        $projects = LeadMaster::where('is_active', true)
            ->whereIn('branch_id', $allowedBranchIds)
            ->orderBy('project_name')->get();

        $record = $danaTalangan;
        $accessibleBranchIds = $allowedBranchIds;
        $kavlings = Kavling::with('project')
            ->whereHas('project', fn ($query) => $query->whereIn('branch_id', $accessibleBranchIds))
            ->orderBy('kavling_code')->get();

        return view('crm.dana-talangan.edit', compact('record', 'branches', 'projects', 'kavlings'));
    }

    public function update(UpdateDanaTalanganRequest $request, DanaTalangan $danaTalangan, DanaTalanganService $service, DanaTalanganOptionService $optionService)
    {
        $user = Auth::user();
        abort_unless(in_array((int) $danaTalangan->branch_id, $this->organizationScope->branchIds($user, 'bridge_fund', 'manage'), true), 403);
        $data = $request->validated();
        if (! $this->optimisticLock->matches($danaTalangan, $data['expected_updated_at'] ?? null)) {
            return $this->optimisticLock->conflict($request, $danaTalangan, $data['expected_updated_at'] ?? null);
        }
        unset($data['expected_updated_at']);
        $data['branch_id'] ??= $danaTalangan->branch_id;

        $project = $service->resolveProject($data['project_name'], (int) $data['branch_id']);
        if ($project === null) {
            return back()->withInput()->withErrors(['project_name' => 'Proyek tidak terdaftar tepat pada cabang yang dipilih.']);
        }
        $data['branch_id'] = $project->branch_id;
        $data['project_id'] = $project->id;
        $data['project_name'] = $project->project_name;
        $branch = Branch::findOrFail($project->branch_id);
        abort_unless(in_array((int) $branch->id, $this->organizationScope->branchIds($user, 'bridge_fund', 'manage'), true), 403);
        abort_unless($this->workspaceAccess->canEditBranch($user, $branch), 403);
        $kavChanged = $this->normalizeKav($data['kav'] ?? null) !== $this->normalizeKav($danaTalangan->kav);
        if ($kavChanged && ! $optionService->isValidKavling($branch, $data['project_name'], $data['kav'] ?? null)) {
            return back()->withInput()->withErrors(['kav' => 'Kav tidak terdaftar pada Proyek yang dipilih.']);
        }

        $data['pinjam_nama'] = $request->boolean('pinjam_nama');
        $data['konfirmasi_keuangan'] = $request->boolean('konfirmasi_keuangan');
        $data['updated_by'] = $user->id;

        $result = $this->optimisticLock->execute($request, $danaTalangan, $request->input('expected_updated_at'), function (DanaTalangan $current) use ($data) {
            $current->update($data);

            return $current->fresh();
        });
        if ($result instanceof Response) {
            return $result;
        }
        $danaTalangan = $result;
        $service->updated($danaTalangan, $user);
        $this->notifications->recordUpdated($danaTalangan, $user, route('dana-talangan.edit', $danaTalangan));
        $this->presence->clearEditing($user, $danaTalangan, $request->input('presence_session_key'));

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Data dana talangan berhasil diperbarui.',
                'reload_url' => route('dana-talangan.index'),
                'updated_at' => $this->optimisticLock->token($danaTalangan->fresh()),
            ]);
        }

        return redirect()->route('dana-talangan.index')
            ->with('success', 'Data dana talangan berhasil diperbarui.');
    }

    public function export(Request $request)
    {
        $user = Auth::user();
        abort_unless($user->hasPermission('bridge_fund.export'), 403);
        $allowedBranchIds = $this->organizationScope->branchIds($user, 'bridge_fund', 'export');
        $selectedBranchId = $request->integer('branch_id') ?: null;
        abort_if($selectedBranchId && ! in_array($selectedBranchId, $allowedBranchIds, true), 403);
        $query = DanaTalangan::with(['branch', 'creator'])->whereIn('branch_id', $allowedBranchIds)
            ->when($selectedBranchId, fn ($query) => $query->where('branch_id', $selectedBranchId));

        $query->when($request->get('project_name'), fn ($q, $v) => $q->where('project_name', $v));
        $query->when($request->get('status'), fn ($q, $v) => $q->where('status', $v));
        $query->when(trim((string) $request->get('search')) !== '', fn ($q) => $q->whereRaw(
            'LOWER(nama_konsumen) LIKE ?',
            ['%'.mb_strtolower(trim((string) $request->get('search'))).'%']
        ));
        [, , , , , $rangeStart, $rangeEnd] = $this->resolveDateRange($request);
        if ($rangeStart) {
            $query->whereDate('tanggal', '>=', $rangeStart);
        }
        if ($rangeEnd) {
            $query->whereDate('tanggal', '<=', $rangeEnd);
        }

        $records = $query->latest('tanggal')->get();

        $filename = 'dana-talangan-'.now()->format('Y-m-d').'.xlsx';

        return DanaTalanganExport::toBrowser($records, $filename);
    }

    public function detail(DanaTalangan $danaTalangan)
    {
        $user = Auth::user();
        abort_unless(in_array((int) $danaTalangan->branch_id, $this->organizationScope->branchIds($user, 'bridge_fund'), true), 403);
        abort_unless($this->workspaceAccess->canViewBranch($user, $danaTalangan->branch_id), 403);

        $danaTalangan->load('creator')->loadCount('comments');

        return response()->json($danaTalangan);
    }

    public function destroy(DanaTalangan $danaTalangan, DanaTalanganService $service)
    {
        $user = Auth::user();
        abort_unless(in_array((int) $danaTalangan->branch_id, $this->organizationScope->branchIds($user, 'bridge_fund', 'manage'), true), 403);
        abort_unless($this->workspaceAccess->canEditBranch($user, $danaTalangan->branch_id), 403);

        try {
            $service->delete($danaTalangan, $user);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Data belum dihapus karena tombstone remote belum aman.');
        }

        return redirect()->route('dana-talangan.index', array_filter(request()->only($this->bulkRedirectParams)))
            ->with('success', 'Data dana talangan berhasil dihapus.');
    }

    public function sync(Request $request)
    {
        $user = Auth::user();
        abort_unless($user->hasPermission('bridge_fund.manage_all'), 403);
        try {
            $result = app(DanaTalanganBridgeService::class)->pull($user);
        } catch (Throwable $exception) {
            report($exception);
            $result = ['ok' => false, 'message' => 'Layanan sinkronisasi global tidak dapat dijalankan.', 'summary' => []];
        }
        if (($result['status'] ?? null) === 'disabled') {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'status' => 'disabled', 'message' => 'Mode bridge Dana Talangan belum bidirectional.'], 422);
            }

            return back()->with('warning', 'Mode bridge Dana Talangan belum bidirectional.');
        }
        $status = DanaTalanganSyncStatus::with('initiator')->where('spreadsheet_id', config('services.google_sheets.dana_talangan_spreadsheet_id'))->first();
        $payload = $this->syncResponses->make('dana-talangan', ['type' => 'global', 'id' => null, 'name' => 'Global'], $status, $result);
        $payload['status_url'] = route('dana-talangan.sync-status');
        if (($result['code'] ?? null) !== 'sync_already_running') {
            $this->notifications->syncResult($user, 'Dana Talangan', 'Global', $result, route('dana-talangan.index'));
            if (! ($result['ok'] ?? false)) {
                $this->notifications->criticalGlobalSyncFailure($user, 'Dana Talangan', route('dana-talangan.index'));
            }
        }
        if (! $result['ok']) {
            if ($request->expectsJson()) {
                return response()->json($payload, ($result['code'] ?? null) === 'sync_already_running' ? 409 : 422);
            }

            return back()->with('error', $payload['message']);
        }

        $summary = $result['summary'];
        $warningCount = count($summary['warnings'] ?? []);
        $warningText = $warningCount > 0 ? " {$warningCount} peringatan perlu diperiksa." : '';

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return back()->with($warningCount > 0 || ($summary['unresolved'] ?? 0) > 0 ? 'warning' : 'success', 'Sync selesai: '.($summary['updated'] ?? 0).' diperbarui, '.($summary['unresolved'] ?? 0).' perlu ditinjau.'.$warningText);
    }

    public function syncStatus(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('bridge_fund.manage_all'), 403);
        $status = DanaTalanganSyncStatus::with('initiator')->where('spreadsheet_id', config('services.google_sheets.dana_talangan_spreadsheet_id'))->first();
        $payload = $this->syncResponses->make('dana-talangan', ['type' => 'global', 'id' => null, 'name' => 'Global'], $status);
        $payload['status_url'] = route('dana-talangan.sync-status');

        return response()->json($payload);
    }

    public function kavlingOptions(Request $request, DanaTalanganService $service, DanaTalanganOptionService $optionService)
    {
        $validated = $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
            'project_name' => 'required|string|max:255',
        ]);
        $user = Auth::user();
        $branch = Branch::where('is_active', true)->findOrFail($validated['branch_id']);
        abort_unless(in_array((int) $branch->id, $this->organizationScope->branchIds($user, 'bridge_fund'), true), 403);
        abort_unless($this->workspaceAccess->canViewBranch($user, $branch), 403);
        if ($service->resolveProject($validated['project_name'], $branch->id) === null) {
            abort(422, 'Proyek tidak terdaftar tepat pada cabang yang dipilih.');
        }

        return response()->json([
            'options' => $optionService->kavlings($branch, $validated['project_name']),
        ]);
    }

    public function importStore(Request $request)
    {
        return $this->traitImportStore($request);
    }

    public function bulkDestroy(Request $request, DanaTalanganService $service)
    {
        abort_unless($request->user()->hasPermission('bridge_fund.manage'), 403);
        $deleted = 0;
        $failed = 0;
        foreach ($this->bulkRecords($request) as $record) {
            try {
                $service->delete($record, $request->user());
                $deleted++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        return back()->with($failed ? 'warning' : 'success', "{$deleted} data dihapus; {$failed} data menunggu tombstone remote.");
    }

    public function bulkUpdate(Request $request, DanaTalanganService $service)
    {
        abort_unless($request->user()->hasPermission('bridge_fund.manage'), 403);
        $status = $request->validate(['new_status' => 'required|in:sanggup,tidak_sanggup,lunas'])['new_status'];
        $updated = 0;
        foreach ($this->bulkRecords($request) as $record) {
            $record->update(['status' => $status, 'updated_by' => $request->user()->id]);
            $service->updated($record, $request->user());
            $updated++;
        }

        return back()->with('success', "{$updated} data dana talangan berhasil diperbarui.");
    }

    public function retry(DanaTalangan $danaTalangan, DanaTalanganService $service)
    {
        $user = Auth::user();
        abort_unless(in_array((int) $danaTalangan->branch_id, $this->organizationScope->branchIds($user, 'bridge_fund', 'manage'), true), 403);
        abort_unless($this->workspaceAccess->canEditBranch($user, $danaTalangan->branch_id), 403);
        $result = $service->retry($danaTalangan, $user);

        return back()->with(($result['ok'] ?? false) ? 'success' : 'warning', ($result['ok'] ?? false) ? 'Pengiriman ulang berhasil.' : 'Pengiriman ulang belum berhasil; periksa rekonsiliasi.');
    }

    public function reconciliation(Request $request)
    {
        abort_unless($request->user()->hasPermission('bridge_fund.manage_all'), 403);
        $items = DanaTalanganReconciliationItem::query()->where('status', 'open')->latest()->paginate(30);

        return view('crm.dana-talangan.reconciliation', compact('items'));
    }

    public function approveReconciliation(DanaTalanganReconciliationItem $reconciliationItem, DanaTalanganBridgeService $bridge)
    {
        $user = Auth::user();
        abort_unless($user->hasPermission('bridge_fund.manage_all'), 403);
        try {
            $bridge->approveRemoteCreate($reconciliationItem, $user);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Baris remote tidak dapat disetujui karena berubah atau tidak valid.');
        }

        return back()->with('success', 'Baris remote berhasil disetujui dan masuk ke OASIS.');
    }

    private function bulkRecords(Request $request)
    {
        $ids = array_filter(explode(',', $request->input('selected_ids', '')));
        if (empty($ids)) {
            return collect();
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        $records = DanaTalangan::whereIn('id', $ids)->get();
        abort_unless($records->count() === count($ids), 403);
        foreach ($records as $record) {
            abort_unless(in_array((int) $record->branch_id, $this->organizationScope->branchIds(Auth::user(), 'bridge_fund', 'manage'), true), 403);
            abort_unless($this->workspaceAccess->canEditBranch(Auth::user(), (int) $record->branch_id), 403);
        }

        return $records;
    }

    private function resolveDateRange(Request $request): array
    {
        $filterMode = $request->get('filter_mode', 'date');
        $filterMode = in_array($filterMode, ['date', 'month'], true) ? $filterMode : 'date';
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');
        $monthFrom = $request->get('month_from');
        $monthTo = $request->get('month_to');
        $start = null;
        $end = null;

        try {
            if ($filterMode === 'month') {
                $start = $monthFrom ? Carbon::createFromFormat('!Y-m', $monthFrom)->startOfMonth()->toDateString() : null;
                $end = $monthTo ? Carbon::createFromFormat('!Y-m', $monthTo)->endOfMonth()->toDateString() : null;
            } else {
                $start = $dateFrom ? Carbon::createFromFormat('!Y-m-d', $dateFrom)->toDateString() : null;
                $end = $dateTo ? Carbon::createFromFormat('!Y-m-d', $dateTo)->toDateString() : null;
            }
        } catch (Throwable) {
            $start = null;
            $end = null;
        }

        if ($start && $end && $start > $end) {
            [$start, $end] = [$end, $start];
        }

        return [$filterMode, $dateFrom, $dateTo, $monthFrom, $monthTo, $start, $end];
    }

    private function normalizeKav(?string $value): string
    {
        return mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', trim((string) $value)));
    }
}
