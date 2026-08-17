<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ConsumerImportBatch;
use App\Models\LeadMaster;
use App\Services\ConsumerHistoricalProcessImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsumerHistoricalProcessImportController extends Controller
{
    public function __construct(private readonly ConsumerHistoricalProcessImportService $importer) {}

    public function create(): View
    {
        abort_unless(request()->user()->isSuperadmin(), 403);

        return view('crm.consumer-historical-import.create', [
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(),
            'processTypes' => ConsumerHistoricalProcessImportService::PROCESS_TYPES,
        ]);
    }

    public function projects(Request $request): array
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        return LeadMaster::query()->where('branch_id', $request->integer('branch_id'))->where('is_active', true)->orderBy('project_name')->get(['id', 'project_name'])->toArray();
    }

    public function preview(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'project_id' => ['required', 'integer', 'exists:lead_master,id'],
            'process_type' => ['required', 'string', 'in:'.implode(',', array_keys(ConsumerHistoricalProcessImportService::PROCESS_TYPES))],
            'tsv' => ['required', 'string', 'max:262144'],
        ]);
        $branch = Branch::query()->where('is_active', true)->findOrFail($data['branch_id']);
        $project = LeadMaster::query()->where('branch_id', $branch->id)->where('is_active', true)->findOrFail($data['project_id']);

        try {
            $batch = $this->importer->createBatch($request->user(), $branch, $project, $data['tsv'], $data['process_type']);
        } catch (\InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['tsv' => $exception->getMessage()]);
        }

        return redirect()->route('historical-process-import.show', $batch);
    }

    public function show(ConsumerImportBatch $consumer_import_batch): View
    {
        abort_unless(request()->user()->isSuperadmin(), 403);
        $batch = $consumer_import_batch->load(['branch', 'project', 'rows']);

        return view('crm.consumer-historical-import.preview', compact('batch'));
    }

    public function confirm(Request $request, ConsumerImportBatch $consumer_import_batch): RedirectResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);
        $data = $request->validate(['expected_updated_at' => ['required', 'date']]);
        abort_unless($consumer_import_batch->updated_at?->toISOString() === $data['expected_updated_at'], 409, 'Preview impor telah berubah.');
        $result = $this->importer->import($consumer_import_batch, $request->user());

        return redirect()->route('historical-process-import.show', $result['batch'])->with('success', 'Import proses historis selesai.');
    }
}
