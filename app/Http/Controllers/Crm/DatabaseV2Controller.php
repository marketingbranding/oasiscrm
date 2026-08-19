<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\DatabaseV2ImportService;
use App\Services\OrganizationScopeService;
use App\Services\WorkspaceAccessService;
use App\Support\DatabaseV2ModuleConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseV2Controller extends Controller
{
    public function __construct(
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly OrganizationScopeService $organizationScope,
        private readonly DatabaseV2ImportService $importService,
    ) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedBranchId = $request->get('branch_id');

        $allowedBranchIds = $this->organizationScope->branchIds($user, 'database_v2');
        $branches = $this->workspaceAccess->accessibleBranches($user)->whereIn('id', $allowedBranchIds)->values();
        $selectedBranch = $this->workspaceAccess->resolveRequestedBranch($user, $selectedBranchId);
        if ($selectedBranchId && (! $selectedBranch || ! in_array((int) $selectedBranch->id, $allowedBranchIds, true))) {
            abort(403);
        }
        $selectedBranch ??= $branches->first();
        $selectedBranchId = $selectedBranch?->id;

        $canEdit = $user->hasPermission('database_v2.edit')
            && $selectedBranch
            && in_array((int) $selectedBranch->id, $this->organizationScope->branchIds($user, 'database_v2', 'manage'), true)
            && $this->workspaceAccess->canEditBranch($user, $selectedBranch);

        $modules = DatabaseV2ModuleConfig::MODULES;
        $labels = DatabaseV2ModuleConfig::labels();
        $requestModule = $request->get('module', array_key_first($modules));
        $requestAdd = $request->boolean('add');

        return view('crm.database-v2.index', compact(
            'branches', 'selectedBranch', 'selectedBranchId', 'modules', 'labels', 'requestModule', 'requestAdd', 'canEdit',
        ));
    }

    public function list(Request $request, string $module)
    {
        $config = $this->moduleConfigOrFail($module);
        $branch = $this->resolveBranchOrFail($request);

        $search = trim((string) $request->get('search', ''));
        $page = (int) $request->get('page', 1);
        $perPage = 50;

        $query = $config['model']::query()
            ->where('branch_id', $branch->id)
            ->when($search, function ($q) use ($search, $config) {
                $fields = $config['fields'];
                $q->where(function ($sub) use ($search, $fields) {
                    foreach ($fields as $i => $field) {
                        if ($i === 0) {
                            $sub->where($field, 'like', "%{$search}%");
                        } else {
                            $sub->orWhere($field, 'like', "%{$search}%");
                        }
                    }
                });
            })
            ->orderByDesc('updated_at');

        $total = $query->count();
        $records = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        $tableFields = $config['table'];

        return response()->json([
            'records' => $records->map(fn ($r) => array_merge(
                ['id' => $r->id],
                collect($tableFields)->mapWithKeys(fn ($f) => [$f => $r->{$f}])->toArray(),
            )),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    public function store(Request $request, string $module)
    {
        $config = $this->moduleConfigOrFail($module);
        $branch = $this->resolveBranchOrFail($request);
        $this->authorizeEdit($branch);

        $data = $request->only($config['fields']);
        $data['branch_id'] = $branch->id;
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        try {
            $record = $config['model']::create($data);
        } catch (\Throwable $e) {
            report($e);
            $errorId = uniqid('dbv2_store_');
            \Log::error('Database V2 store failed: '.$errorId, ['exception' => $e->getMessage()]);

            return response()->json(['ok' => false, 'message' => 'Gagal menyimpan. Referensi: '.$errorId], 500);
        }

        return response()->json(['ok' => true, 'id' => $record->id, 'message' => 'Data berhasil ditambahkan.']);
    }

    public function update(Request $request, string $module, int $id)
    {
        $config = $this->moduleConfigOrFail($module);
        $branch = $this->resolveBranchOrFail($request);
        $this->authorizeEdit($branch);

        $record = $config['model']::where('branch_id', $branch->id)->findOrFail($id);
        $data = $request->only($config['fields']);
        $data['updated_by'] = Auth::id();

        try {
            $record->update($data);
        } catch (\Throwable $e) {
            report($e);
            $errorId = uniqid('dbv2_update_');
            \Log::error('Database V2 update failed: '.$errorId, ['exception' => $e->getMessage()]);

            return response()->json(['ok' => false, 'message' => 'Gagal menyimpan. Referensi: '.$errorId], 500);
        }

        return response()->json(['ok' => true, 'message' => 'Data berhasil diperbarui.']);
    }

    public function destroy(Request $request, string $module, int $id)
    {
        $config = $this->moduleConfigOrFail($module);
        $branch = $this->resolveBranchOrFail($request);
        $this->authorizeEdit($branch);

        $record = $config['model']::where('branch_id', $branch->id)->findOrFail($id);
        $record->delete();

        return response()->json(['ok' => true, 'message' => 'Data berhasil diarsipkan.']);
    }

    public function importPreview(Request $request, string $module)
    {
        $this->moduleConfigOrFail($module);
        $this->resolveBranchOrFail($request);
        $request->validate(['raw' => 'required|string']);

        return response()->json($this->importService->preview($module, $request->input('raw')));
    }

    public function importSave(Request $request, string $module)
    {
        $branch = $this->resolveBranchOrFail($request);
        $this->authorizeEdit($branch);
        $request->validate(['raw' => 'required|string']);
        $validOnly = $request->boolean('valid_only');

        try {
            return response()->json($this->importService->save(
                $module, $request->input('raw'), $branch->id, Auth::id(), $validOnly
            ));
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Validasi gagal: '.implode(' ', array_map(fn ($m) => implode(', ', $m), array_values($e->errors()))),
            ], 422);
        } catch (\Throwable $e) {
            report($e);
            $errorId = uniqid('dbv2_import_');
            \Log::error('Database V2 import save failed: '.$errorId, ['exception' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'message' => 'Import gagal. Referensi: '.$errorId,
            ], 500);
        }
    }

    public function export(Request $request, string $module): BinaryFileResponse
    {
        $config = $this->moduleConfigOrFail($module);
        $branch = $this->resolveBranchOrFail($request);

        $records = $config['model']::where('branch_id', $branch->id)->orderBy('id')->get();
        $labels = DatabaseV2ModuleConfig::labels();
        $tableFields = $config['table'];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $col = 1;
        foreach ($tableFields as $field) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col).'1', $labels[$field] ?? $field);
            $col++;
        }

        $rowNum = 2;
        foreach ($records as $record) {
            $col = 1;
            foreach ($tableFields as $field) {
                $cell = Coordinate::stringFromColumnIndex($col).$rowNum;
                $value = $record->{$field};
                if (in_array($field, $config['date']) && $value) {
                    $sheet->setCellValue($cell, $value->format('Y-m-d'));
                } elseif (in_array($field, $config['money']) && $value !== null) {
                    $sheet->setCellValue($cell, (float) $value);
                } elseif (in_array($field, $config['integer']) && $value !== null) {
                    $sheet->setCellValue($cell, (int) $value);
                } else {
                    $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
                }
                $col++;
            }
            $rowNum++;
        }

        $writer = new Xlsx($spreadsheet);
        $fileName = ($branch->code ?? 'branch').'_'.$module.'_'.now()->format('Y-m-d').'.xlsx';
        $tempFile = tempnam(sys_get_temp_dir(), 'dbv2_').'.xlsx';
        $writer->save($tempFile);
        $spreadsheet->disconnectWorksheets();

        return response()->download($tempFile, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function moduleConfigOrFail(string $module): array
    {
        $config = DatabaseV2ModuleConfig::config($module);
        abort_unless($config, 404);

        return $config;
    }

    private function resolveBranchOrFail(Request $request): Branch
    {
        $branch = $this->workspaceAccess->resolveRequestedBranch(Auth::user(), $request->get('branch_id') ?: $request->header('X-Branch-Id'));
        abort_unless($branch, 403);

        $user = Auth::user();
        abort_unless(in_array((int) $branch->id, $this->organizationScope->branchIds($user, 'database_v2'), true), 403);
        abort_unless($this->workspaceAccess->canViewBranch($user, $branch), 403);

        return $branch;
    }

    private function authorizeEdit(Branch $branch): void
    {
        $user = Auth::user();
        abort_unless($user->hasPermission('database_v2.edit'), 403);
        abort_unless(in_array((int) $branch->id, $this->organizationScope->branchIds($user, 'database_v2', 'manage'), true), 403);
        abort_unless($this->workspaceAccess->canEditBranch($user, $branch), 403);
    }
}
