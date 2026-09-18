<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\MarisonMigrationConfirmRequest;
use App\Http\Requests\MarisonMigrationPreviewRequest;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\MarisonImportBatch;
use App\Models\MarisonProjectMapping;
use App\Services\MarisonV2ImportPreviewService;
use App\Services\MarisonV2ImportService;
use App\Services\MarisonV2PackageValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MarisonMigrationController extends Controller
{
    public function create(Request $request): View
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        return view('crm.admin.marison-migrations.create', [
            'mappings' => MarisonProjectMapping::query()->with('project.branch')->orderBy('branch_code')->orderBy('source_project_id')->get(),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(),
            'projects' => LeadMaster::query()->with('branch')->where('is_active', true)->orderBy('project_name')->get(),
        ]);
    }

    public function preview(MarisonMigrationPreviewRequest $request, MarisonV2ImportPreviewService $preview): RedirectResponse
    {
        $file = $request->file('package');
        $batch = $preview->stage($file, $request->user(), $file->getClientOriginalName());
        ActivityLog::query()->create([
            'causer_id' => $request->user()->id, 'subject_type' => MarisonImportBatch::class, 'subject_id' => $batch->id,
            'event' => 'marison_import_preview_generated', 'description' => 'Preview paket migrasi Marison V2 dibuat.',
            'properties' => ['batch_id' => $batch->public_id, 'branch_code' => $batch->source_branch_code, 'payload_hash' => $batch->payload_hash, 'counts' => $batch->counts],
        ]);

        return to_route('admin.marison-migrations.batches.show', $batch);
    }

    public function history(Request $request): View
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        return view('crm.admin.marison-migrations.history', ['batches' => MarisonImportBatch::query()->with('uploader')->latest()->paginate(20)]);
    }

    public function show(Request $request, MarisonImportBatch $marisonImportBatch): View
    {
        abort_unless($request->user()->isSuperadmin(), 403);
        $marisonImportBatch->load(['transactions.projectMapping.project', 'unlinkedRecords']);

        return view('crm.admin.marison-migrations.show', ['batch' => $marisonImportBatch]);
    }

    public function confirm(MarisonMigrationConfirmRequest $request, MarisonImportBatch $marisonImportBatch, MarisonV2ImportService $importer): RedirectResponse
    {
        $result = $importer->confirm($marisonImportBatch, $request->user(), $request->validated('preview_hash'), (int) $request->validated('preview_version'));

        return to_route('admin.marison-migrations.batches.show', $marisonImportBatch)->with('success', "Impor selesai: {$result['created']} aplikasi dibuat, {$result['already']} sudah ada, {$result['review']} menunggu review.");
    }

    public function storeMapping(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);
        $data = $request->validate([
            'branch_code' => ['required', 'string', Rule::exists('branches', 'code')],
            'source_project_id' => ['required', 'string', 'max:100'],
            'oasis_project_id' => ['required', 'integer', Rule::exists('lead_master', 'id')],
        ]);
        $project = LeadMaster::query()->findOrFail($data['oasis_project_id']);
        $branch = Branch::query()->where('code', $data['branch_code'])->firstOrFail();
        abort_unless((int) $project->branch_id === (int) $branch->id, 422, 'Proyek OASIS harus berada pada cabang yang sama.');
        MarisonProjectMapping::query()->updateOrCreate([
            'source_system' => MarisonV2PackageValidator::SOURCE_SYSTEM, 'branch_code' => $data['branch_code'], 'source_project_id' => $data['source_project_id'],
        ], ['oasis_project_id' => $project->id]);

        return back()->with('success', 'Pemetaan proyek Marison berhasil disimpan.');
    }
}
