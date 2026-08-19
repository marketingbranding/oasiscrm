<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DatabaseSheetRecord;
use App\Models\DatabaseSheetSyncStatus;
use App\Services\CollaborationNotificationService;
use App\Services\DatabaseFieldConfig;
use App\Services\DatabaseSheetImportService;
use App\Services\DatabaseSheetSyncService;
use App\Services\DatabaseSheetWriteService;
use App\Services\GoogleSheetsApiService;
use App\Services\OptimisticLockService;
use App\Services\OrganizationScopeService;
use App\Services\PresenceService;
use App\Services\SyncResponseService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DatabaseController extends Controller
{
    public const SHEET_MODULES = [
        'data_konsumen' => 'Data Konsumen',
        'bi_checking' => 'BI Checking',
        'PSJB' => 'PSJB',
        'pemberkasan' => 'Pemberkasan',
        'proses_bank' => 'Proses Bank',
        'ppjb_dev' => 'PPJB Developer',
        'akad' => 'Akad',
        'bast' => 'BAST',
    ];

    public function __construct(
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly OptimisticLockService $optimisticLock,
        private readonly CollaborationNotificationService $notifications,
        private readonly PresenceService $presence,
        private readonly SyncResponseService $syncResponses,
        private readonly OrganizationScopeService $organizationScope,
    ) {}

    public function index(Request $request, GoogleSheetsApiService $googleSheets)
    {
        $user = Auth::user();
        $selectedBranchId = $request->get('branch_id');

        $allowedBranchIds = $this->organizationScope->branchIds($user, 'database');
        $branches = $this->workspaceAccess->accessibleBranches($user)->whereIn('id', $allowedBranchIds)->values();
        $selectedBranch = $this->workspaceAccess->resolveRequestedBranch($user, $selectedBranchId);
        if ($selectedBranchId && (! $selectedBranch || ! in_array((int) $selectedBranch->id, $allowedBranchIds, true))) {
            abort(403);
        }
        $selectedBranch ??= $branches->first();
        $selectedBranchId = $selectedBranch?->id;
        $sheetNames = [];
        $records = [];
        $syncStatus = null;
        $isStale = true;

        if ($selectedBranch && $selectedBranch->sheet_id) {
            $syncStatus = DatabaseSheetSyncStatus::where('branch_id', $selectedBranch->id)->first();
            $isStale = $syncStatus?->status !== 'success' || ! $syncStatus?->finished_at
                || $syncStatus->finished_at->lt(now()->subMinutes((int) config('services.google_sheets.cache_stale_minutes', 30)));

            $cachedSheetNames = DatabaseSheetRecord::where('branch_id', $selectedBranch->id)
                ->whereNull('oasis_deleted_at')
                ->select('sheet_name')
                ->distinct()
                ->orderBy('sheet_name')
                ->pluck('sheet_name');

            $orderedSheetNames = [];
            $sheetSeen = [];

            try {
                $apiSheetNames = $googleSheets->sheetTitles($selectedBranch->sheet_id);
                foreach ($apiSheetNames as $name) {
                    $orderedSheetNames[] = $name;
                    $sheetSeen[$name] = true;
                    if (! isset($records[$name])) {
                        $records[$name] = [];
                    }
                }
            } catch (Throwable $e) {
                // API unavailable, show only sheets with records
            }

            foreach ($cachedSheetNames as $name) {
                if (! isset($sheetSeen[$name])) {
                    $orderedSheetNames[] = $name;
                    $sheetSeen[$name] = true;
                }
            }

            $sheetNames = $orderedSheetNames;

            $firstSheet = $sheetNames[0] ?? null;
            if ($firstSheet) {
                $records[$firstSheet] = DatabaseSheetRecord::where('branch_id', $selectedBranch->id)
                    ->where('sheet_name', $firstSheet)
                    ->whereNull('oasis_deleted_at')
                    ->orderBy('row_number')
                    ->get([
                        'id',
                        'sheet_name',
                        'row_number',
                        'oasis_sync_id',
                        'headers',
                        'row_data',
                        'formula_columns',
                        'column_metadata',
                        'updated_at',
                    ])
                    ->all();
            }
        }

        $requestSheet = $request->get('sheet');
        $requestAdd = $request->boolean('add');

        $canSync = $user->hasPermission('database.sync') && $selectedBranch && $this->workspaceAccess->canSyncBranch($user, $selectedBranch);
        $sheetCounts = [];

        if ($selectedBranch) {
            $sheetCounts = $this->businessRowCount($selectedBranch);
        }

        $canEdit = $user->hasPermission('database.edit')
            && $selectedBranch
            && in_array((int) $selectedBranch->id, $this->organizationScope->branchIds($user, 'database', 'manage'), true)
            && $this->workspaceAccess->canEditBranch($user, $selectedBranch);

        $fieldConfig = DatabaseFieldConfig::config();

        return view('crm.database.index', compact('branches', 'selectedBranch', 'selectedBranchId', 'sheetNames', 'records', 'syncStatus', 'isStale', 'requestSheet', 'requestAdd', 'canSync', 'canEdit', 'sheetCounts', 'fieldConfig'));
    }

    public function sheetData(Request $request, $branchId, $sheetName)
    {
        $branch = Branch::findOrFail($branchId);

        $user = Auth::user();
        abort_unless($user->hasPermission('database.view'), 403);
        abort_unless(in_array((int) $branch->id, $this->organizationScope->branchIds($user, 'database'), true), 403);
        abort_unless($this->workspaceAccess->canViewBranch($user, $branch), 403);

        $rows = DatabaseSheetRecord::where('branch_id', $branch->id)
            ->where('sheet_name', $sheetName)
            ->whereNull('oasis_deleted_at')
            ->orderBy('row_number')
            ->get(['id', 'row_number', 'oasis_sync_id', 'row_data', 'headers', 'formula_columns', 'column_metadata', 'updated_at']);

        $sample = $rows->first();
        $headers = $sample ? $sample->headers : [];
        $formulaColumns = $sample ? ($sample->formula_columns ?? []) : [];
        $columnMetadata = $sample ? ($sample->column_metadata ?? []) : [];

        $records = $rows->map(fn ($r) => [
            'id' => $r->id,
            'row_number' => $r->row_number,
            'oasis_sync_id' => $r->oasis_sync_id,
            'updated_at' => $this->optimisticLock->token($r),
            'row_data' => $r->row_data,
        ]);

        return response()->json([
            'sheet_name' => $sheetName,
            'headers' => $headers,
            'formula_columns' => $formulaColumns,
            'column_metadata' => $columnMetadata,
            'records' => $records,
        ]);
    }

    public function sync(Request $request)
    {
        $user = Auth::user();
        abort_unless($user->hasPermission('database.sync'), 403);
        $branch = $this->workspaceAccess->resolveRequestedBranch($user, $request->input('branch_id'));

        if ($request->filled('branch_id') && ! $branch) {
            abort(403);
        }

        if (! $branch) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => 'Branch tidak ditemukan.'], 422);
            }

            return back()->with('error', 'Branch tidak ditemukan.');
        }
        abort_unless(in_array((int) $branch->id, $this->organizationScope->branchIds($user, 'database', 'sync'), true), 403);
        abort_unless($this->workspaceAccess->canSyncBranch($user, $branch), 403);

        try {
            $result = app(DatabaseSheetSyncService::class)->syncBranch($branch, $user->id);
        } catch (Throwable $exception) {
            report($exception);
            $result = ['ok' => false, 'message' => 'Layanan sinkronisasi tidak dapat dijalankan.', 'summary' => []];
        }
        $status = DatabaseSheetSyncStatus::with('initiator')->where('branch_id', $branch->id)->first();
        $payload = $this->syncResponses->make('database', ['type' => 'branch', 'id' => $branch->id, 'name' => $branch->name], $status, $result);
        $payload['status_url'] = route('database.sync-status', ['branch_id' => $branch->id]);
        if (($result['code'] ?? null) !== 'sync_already_running') {
            $this->notifications->syncResult($user, 'Database', $branch->name, $result, route('database.index', ['branch_id' => $branch->id]), array_sum($result['summary'] ?? []));
        }

        if ($request->expectsJson()) {
            return response()->json($payload, ($result['code'] ?? null) === 'sync_already_running' ? 409 : ($payload['status'] === 'failed' ? 422 : 200));
        }

        return back()->with($payload['status'] === 'success' ? 'success' : 'error', $payload['message']);
    }

    public function syncStatus(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('database.sync'), 403);
        $branch = $this->workspaceAccess->resolveRequestedBranch($user, $request->query('branch_id'));
        abort_unless($branch && $this->workspaceAccess->canSyncBranch($user, $branch), 403);
        abort_unless(in_array((int) $branch->id, $this->organizationScope->branchIds($user, 'database', 'sync'), true), 403);
        $status = DatabaseSheetSyncStatus::with('initiator')->where('branch_id', $branch->id)->first();
        $payload = $this->syncResponses->make('database', ['type' => 'branch', 'id' => $branch->id, 'name' => $branch->name], $status);
        $payload['status_url'] = route('database.sync-status', ['branch_id' => $branch->id]);

        return response()->json($payload);
    }

    public function store(Request $request, DatabaseSheetWriteService $writeService)
    {
        $request->validate([
            'sheet_name' => 'required|string',
            'branch_id' => 'required|exists:branches,id',
        ]);

        $branch = Branch::findOrFail($request->input('branch_id'));
        $sheetName = $request->input('sheet_name');

        $user = Auth::user();
        abort_unless($user->hasPermission('database.edit'), 403);
        abort_unless(in_array((int) $branch->id, $this->organizationScope->branchIds($user, 'database', 'manage'), true), 403);
        abort_unless($this->workspaceAccess->canEditBranch($user, $branch), 403);

        $input = $request->except(['_token', 'sheet_name', 'branch_id']);
        $template = DatabaseSheetRecord::where('branch_id', $branch->id)
            ->where('sheet_name', $sheetName)
            ->orderByDesc('row_number')
            ->first();
        $this->validateTypedInput($input, $template?->column_metadata ?? []);

        if (! $writeService->createRecord($branch, $sheetName, $input)) {
            return back()->with('error', 'Gagal menambah data. Tidak ada template row atau Google Sheets tidak merespons.');
        }

        return back()->with('success', 'Data berhasil ditambahkan.');
    }

    public function update(Request $request, $record, DatabaseSheetWriteService $writeService)
    {
        $routeRecordId = (int) $record;
        $record = DatabaseSheetRecord::find($routeRecordId);
        $recordWasReplaced = false;
        if (! $record && $request->filled('expected_sync_id')) {
            $record = DatabaseSheetRecord::where('oasis_sync_id', $request->input('expected_sync_id'))->first();
            $recordWasReplaced = (bool) $record;
        }
        abort_unless($record, 404);

        $branch = $record->branch;
        if (! $branch) {
            return back()->with('error', 'Branch tidak ditemukan.');
        }

        $user = Auth::user();
        abort_unless($user->hasPermission('database.edit'), 403);
        abort_unless(in_array((int) $branch->id, $this->organizationScope->branchIds($user, 'database', 'manage'), true), 403);
        abort_unless($this->workspaceAccess->canEditBranch($user, $branch), 403);
        if ($recordWasReplaced) {
            return $this->optimisticLock->conflict($request, $record, $request->input('expected_updated_at'));
        }

        $request->validate([
            'expected_updated_at' => ['required', 'string', 'max:40'],
            'expected_sync_id' => ['nullable', 'string', 'max:255'],
        ]);
        $input = $request->except(['_token', '_method', 'expected_updated_at', 'expected_sync_id', 'presence_session_key']);
        $result = $this->optimisticLock->execute($request, $record, $request->input('expected_updated_at'), function (DatabaseSheetRecord $current) use ($request, $input, $user) {
            abort_unless($current->branch && $this->workspaceAccess->canEditBranch($user, $current->branch), 403);
            $identityMatches = blank($current->oasis_sync_id)
                || hash_equals((string) $current->oasis_sync_id, (string) $request->input('expected_sync_id'));
            if (! $identityMatches) {
                return $this->optimisticLock->conflict($request, $current, $request->input('expected_updated_at'));
            }
            $this->validateTypedInput($input, $current->column_metadata ?? [], $current->row_data ?? []);
            $current->update(['sync_status' => 'pending', 'last_sync_error' => null]);

            return $current->fresh();
        });
        if ($result instanceof Response) {
            return $result;
        }
        $record = $result;
        $reloadUrl = route('database.index', ['branch_id' => $branch->id, 'sheet' => $record->sheet_name]);
        if (! $writeService->updateRecord($record, $input)) {
            $record->refresh();
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'local_saved' => false,
                    'message' => 'Perubahan belum tersimpan karena Google Sheets tidak dapat diperbarui. Nilai lokal lama tetap dipertahankan.',
                    'updated_at' => $this->optimisticLock->token($record),
                    'reload_url' => $reloadUrl,
                ], 422);
            }

            return back()->withInput()->with('error', 'Perubahan belum tersimpan karena Google Sheets gagal diperbarui. Nilai lokal lama tetap dipertahankan.');
        }

        $record->refresh();
        $this->notifications->recordUpdated($record, $user, $reloadUrl);
        $this->presence->clearEditing($user, $record, $request->input('presence_session_key'));

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Data berhasil diperbarui dan disinkronkan.',
                'reload_url' => $reloadUrl,
                'updated_at' => $this->optimisticLock->token($record->fresh()),
            ]);
        }

        return back()->with('success', 'Data berhasil diupdate dan tersinkron ke Google Sheets.');
    }

    public function destroy(DatabaseSheetRecord $record, DatabaseSheetWriteService $writeService)
    {
        $branch = $record->branch;
        if (! $branch) {
            return back()->with('error', 'Branch tidak ditemukan.');
        }

        $user = Auth::user();
        abort_unless($user->hasPermission('database.edit'), 403);
        abort_unless(in_array((int) $branch->id, $this->organizationScope->branchIds($user, 'database', 'manage'), true), 403);
        abort_unless($this->workspaceAccess->canEditBranch($user, $branch), 403);

        if (! $writeService->softDelete($record, $user->id)) {
            return back()->with('error', 'Data belum dihapus karena metadata penghapusan gagal dikirim ke Google Sheets.');
        }

        return back()->with('success', 'Data berhasil dihapus.');
    }

    public function importPreview(Request $request, DatabaseSheetImportService $importService)
    {
        $request->validate([
            'sheet_name' => 'required|string',
            'branch_id' => 'required|exists:branches,id',
            'raw' => 'required|string',
        ]);

        $branch = Branch::findOrFail($request->input('branch_id'));
        $sheetName = $request->input('sheet_name');
        $this->authorizeDatabaseEdit($branch);

        $result = $importService->preview($branch, $sheetName, $request->input('raw'));

        return response()->json($result);
    }

    public function importSave(Request $request, DatabaseSheetImportService $importService)
    {
        $request->validate([
            'sheet_name' => 'required|string',
            'branch_id' => 'required|exists:branches,id',
            'raw' => 'required|string',
        ]);

        $branch = Branch::findOrFail($request->input('branch_id'));
        $sheetName = $request->input('sheet_name');
        $this->authorizeDatabaseEdit($branch);

        $result = $importService->save($branch, $sheetName, $request->input('raw'));

        return response()->json($result);
    }

    private function authorizeDatabaseEdit(Branch $branch): void
    {
        $user = Auth::user();
        abort_unless($user->hasPermission('database.edit'), 403);
        abort_unless(in_array((int) $branch->id, $this->organizationScope->branchIds($user, 'database', 'manage'), true), 403);
        abort_unless($this->workspaceAccess->canEditBranch($user, $branch), 403);
    }

    private function businessRowCount(Branch $branch): array
    {
        $identityFields = ['id_kavling', 'no_ktp', 'id_kons', 'id_psjb', 'id_berkas', 'no_sp3k', 'id_ppjb_dev', 'no_ppjb_akad', 'no_bast'];
        $rows = DatabaseSheetRecord::where('branch_id', $branch->id)
            ->whereNull('oasis_deleted_at')
            ->get(['sheet_name', 'headers', 'formula_columns', 'row_data']);
        $counts = [];

        foreach ($rows->groupBy('sheet_name') as $sheetName => $sheetRows) {
            $headers = $sheetRows->first()->headers ?? [];
            $canonicalHeader = collect($identityFields)->first(fn (string $field) => in_array($field, $headers, true));
            $identities = [];
            if ($canonicalHeader !== null) {
                foreach ($sheetRows as $row) {
                    $value = trim((string) (($row->row_data ?? [])[$canonicalHeader] ?? ''));
                    if ($value !== '') {
                        $identities[mb_strtolower($value)] = true;
                    }
                }
            }
            if (array_key_exists($sheetName, self::SHEET_MODULES)) {
                $counts[$sheetName] = count($identities);
            }
        }

        return $counts;
    }

    private function validateTypedInput(array $input, array $columnMetadata, array $existingValues = []): void
    {
        $errors = [];

        foreach ($columnMetadata as $header => $metadata) {
            $value = trim((string) ($input[$header] ?? ''));
            if ($value === '') {
                continue;
            }

            $type = $metadata['type'] ?? 'text';
            if ($type === 'select' && ! empty($metadata['strict']) && ! empty($metadata['options'])
                && ! in_array($value, $metadata['options'], true)
                && $value !== (string) ($existingValues[$header] ?? '')) {
                $errors[$header] = 'Pilihan untuk '.$header.' tidak valid.';
            }

            $format = match ($type) {
                'date' => 'Y-m-d',
                'datetime-local' => 'Y-m-d\TH:i',
                'time' => 'H:i',
                default => null,
            };

            if ($format) {
                $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
                if (! $date || $date->format($format) !== $value) {
                    $errors[$header] = 'Format '.$header.' tidak valid.';
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
