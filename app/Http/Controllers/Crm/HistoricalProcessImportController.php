<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\HistoricalProcessImportBatch;
use App\Services\HistoricalProcessImportService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class HistoricalProcessImportController extends Controller
{
    public function __construct(private HistoricalProcessImportService $service, private WorkspaceAccessService $access) {}

    public function create(Request $request): View
    {
        abort_unless($request->user()->isSuperadmin(), 403, 'Impor proses histori khusus Super Admin.');
        $branches = $this->access->accessibleBranches($request->user());

        return view('crm.historical-process.import', ['branches' => $branches, 'stageLabels' => $this->service->stageLabels()]);
    }

    public function preview(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403, 'Impor proses histori khusus Super Admin.');
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'tsv' => ['required', 'string', 'max:262144'],
        ]);
        abort_unless($this->access->canViewBranch($request->user(), (int) $data['branch_id']), 403);
        try {
            $rows = $this->service->parse($data['tsv']);
        } catch (\InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['tsv' => $exception->getMessage()]);
        }
        if ($rows === []) {
            return back()->withInput()->withErrors(['tsv' => 'Data TSV tidak memiliki baris data.']);
        }
        $rows = $this->service->resolvePreviewRows(Branch::findOrFail($data['branch_id']), $rows);
        $batch = $this->service->stageBatch($rows, $request->user()->id, (int) $data['branch_id']);

        return redirect()->route('historical-process.import.show', $batch);
    }

    public function show(HistoricalProcessImportBatch $historical_process_import_batch): View
    {
        $this->authorize('view', $historical_process_import_batch);

        return view('crm.historical-process.import-preview', ['batch' => $historical_process_import_batch->load(['branch', 'rows'])]);
    }

    public function confirm(Request $request, HistoricalProcessImportBatch $historical_process_import_batch): RedirectResponse
    {
        $this->authorize('confirm', $historical_process_import_batch);
        $data = $request->validate(['expected_updated_at' => ['required', 'date']]);
        try {
            $result = DB::transaction(function () use ($request, $historical_process_import_batch, $data) {
                $batch = HistoricalProcessImportBatch::query()->whereKey($historical_process_import_batch->id)->lockForUpdate()->firstOrFail();
                abort_unless(($batch->uploaded_by === $request->user()->id || $request->user()->isSuperadmin()) && $this->access->canViewBranch($request->user(), $batch->branch_id), 403);
                abort_if($batch->status !== 'preview_ready' || $batch->expires_at->isPast(), 409, 'Preview impor tidak lagi dapat dikonfirmasi.');
                abort_if(! $batch->updated_at->equalTo($data['expected_updated_at']), 409, 'Preview impor telah berubah.');
                $result = $this->service->confirm($batch);
                ActivityLog::create([
                    'causer_id' => $request->user()->id,
                    'subject_type' => HistoricalProcessImportBatch::class,
                    'subject_id' => $batch->id,
                    'event' => 'historical_process_imported',
                    'description' => 'Impor proses histori selesai.',
                    'properties' => ['branch_id' => $batch->branch_id, 'total_rows' => $batch->total_rows, 'created_count' => $result['created'], 'skipped_count' => $result['skipped'], 'actor_id' => $request->user()->id],
                ]);

                return $result;
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getCode() === 409) {
                return back()->withErrors(['batch' => $exception->getMessage()]);
            }
            throw $exception;
        }

        return redirect()->route('historical-process.import.create')
            ->with('success', "Impor selesai: {$result['created']} baris dibuat, {$result['skipped']} dilewati.");
    }
}
