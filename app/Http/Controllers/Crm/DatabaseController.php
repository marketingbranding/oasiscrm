<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DatabaseSheetRecord;
use App\Models\DatabaseSheetSyncStatus;
use App\Services\DatabaseSheetSyncService;
use App\Services\DatabaseSheetWriteService;
use App\Services\GoogleSheetsApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class DatabaseController extends Controller
{
    public function index(Request $request, GoogleSheetsApiService $googleSheets)
    {
        $user = Auth::user();
        $selectedBranchId = $request->get('branch_id');

        if ($user->isSuperadmin()) {
            $branches = Branch::where('is_active', true)->get();
            if (! $selectedBranchId && $branches->isNotEmpty()) {
                $selectedBranchId = $branches->first()->id;
            }
        } else {
            $branches = collect();
            $selectedBranchId = $user->branch_id;
        }

        $selectedBranch = $selectedBranchId ? Branch::find($selectedBranchId) : null;
        $sheetNames = [];
        $records = [];
        $syncStatus = null;
        $isStale = true;

        if ($selectedBranch && $selectedBranch->sheet_id) {
            $syncStatus = DatabaseSheetSyncStatus::where('branch_id', $selectedBranch->id)->first();
            $isStale = $syncStatus?->finished_at
                ? $syncStatus->finished_at->lt(now()->subMinutes((int) config('services.google_sheets.cache_stale_minutes', 30)))
                : true;

            $recordRows = DatabaseSheetRecord::where('branch_id', $selectedBranch->id)
                ->whereNull('oasis_deleted_at')
                ->orderBy('sheet_name')
                ->orderBy('row_number')
                ->get();

            foreach ($recordRows as $row) {
                $records[$row->sheet_name][] = $row;
            }

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

            foreach ($records as $name => $rows) {
                if (! isset($sheetSeen[$name])) {
                    $orderedSheetNames[] = $name;
                    $sheetSeen[$name] = true;
                }
            }

            $sheetNames = $orderedSheetNames;

            // Only keep first sheet's records in memory; rest load via AJAX
            $firstSheet = $sheetNames[0] ?? null;
            foreach (array_keys($records) as $name) {
                if ($name !== $firstSheet) {
                    unset($records[$name]);
                }
            }
        }

        $requestSheet = $request->get('sheet');
        $requestAdd = $request->boolean('add');

        return view('crm.database.index', compact('branches', 'selectedBranch', 'selectedBranchId', 'sheetNames', 'records', 'syncStatus', 'isStale', 'requestSheet', 'requestAdd'));
    }

    public function sheetData(Request $request, $branchId, $sheetName)
    {
        $branch = Branch::findOrFail($branchId);

        $user = Auth::user();
        if (! $user->isSuperadmin() && $user->branch_id !== $branch->id) {
            abort(403);
        }

        $rows = DatabaseSheetRecord::where('branch_id', $branch->id)
            ->where('sheet_name', $sheetName)
            ->whereNull('oasis_deleted_at')
            ->orderBy('row_number')
            ->get(['id', 'row_number', 'row_data', 'headers', 'formula_columns', 'column_metadata']);

        $sample = $rows->first();
        $headers = $sample ? $sample->headers : [];
        $formulaColumns = $sample ? ($sample->formula_columns ?? []) : [];
        $columnMetadata = $sample ? ($sample->column_metadata ?? []) : [];

        $records = $rows->map(fn ($r) => [
            'id' => $r->id,
            'row_number' => $r->row_number,
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

    public function sync(Request $request, DatabaseSheetSyncService $syncService)
    {
        $user = Auth::user();
        $branchId = $user->isSuperadmin() ? $request->input('branch_id') : $user->branch_id;
        $branch = $branchId ? Branch::find($branchId) : null;

        if (! $branch) {
            return back()->with('error', 'Branch tidak ditemukan.');
        }

        $result = $syncService->syncBranch($branch);
        if (! $result['ok']) {
            return back()->with('error', 'Sync gagal: '.$result['message']);
        }

        $totalRows = array_sum($result['summary']);

        return back()->with('success', 'Sync selesai: '.count($result['summary']).' sheets, '.$totalRows.' rows.');
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
        if (! $user->isSuperadmin() && $user->branch_id !== $branch->id) {
            abort(403);
        }

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

    public function update(Request $request, DatabaseSheetRecord $record, DatabaseSheetWriteService $writeService)
    {
        $branch = $record->branch;
        if (! $branch) {
            return back()->with('error', 'Branch tidak ditemukan.');
        }

        $user = Auth::user();
        if (! $user->isSuperadmin() && $user->branch_id !== $branch->id) {
            abort(403);
        }

        $input = $request->except(['_token', '_method']);
        $this->validateTypedInput($input, $record->column_metadata ?? [], $record->row_data ?? []);

        if (! $writeService->updateRecord($record, $input)) {
            return back()->with('error', 'Gagal update. Perubahan tersimpan di database lokal, tapi gagal push ke Google Sheets: '.$record->last_sync_error);
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
        if (! $user->isSuperadmin() && $user->branch_id !== $branch->id) {
            abort(403);
        }

        if (! $writeService->softDelete($record, $user->id)) {
            return back()->with('error', 'Gagal menghapus. Data tetap dihapus di database lokal, tapi gagal sync ke Google Sheets: '.$record->last_sync_error);
        }

        return back()->with('success', 'Data berhasil dihapus.');
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
