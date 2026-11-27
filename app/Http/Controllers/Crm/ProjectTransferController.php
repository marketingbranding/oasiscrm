<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\ProjectTransferRequest;
use App\Http\Requests\Crm\ProjectTransferRollbackRequest;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\ProjectTransferBatch;
use App\Services\ProjectTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ProjectTransferController extends Controller
{
    public function __construct(private readonly ProjectTransferService $transfers) {}

    public function create(): View
    {
        $this->authorizeOwner();

        return view('crm.projects.transfer', [
            'branches' => Branch::query()->where('is_active', true)->with(['users' => fn ($query) => $query->wherePivot('is_current', true)->orderBy('name')])->orderBy('name')->get(['id', 'name', 'sheet_id']),
            'projects' => LeadMaster::query()->where('is_active', true)->with(['assignedUsers' => fn ($query) => $query->wherePivot('is_active', true)->select('users.id')])->orderBy('project_name')->get(['id', 'branch_id', 'project_name']),
        ]);
    }

    public function history(): View
    {
        $this->authorizeOwner();

        return view('crm.projects.transfer-history', [
            'batches' => ProjectTransferBatch::query()->with(['sourceBranch:id,name', 'targetBranch:id,name'])->latest('id')->paginate(20),
        ]);
    }

    public function rollback(ProjectTransferRollbackRequest $request, ProjectTransferBatch $batch): JsonResponse
    {
        $this->authorizeOwner();
        $target = $batch->targetBranch;
        if ($request->validated('confirmation') !== $target->name) {
            return response()->json(['message' => 'Ketik nama cabang tujuan untuk konfirmasi pembatalan.'], 422);
        }

        $this->transfers->rollback($request->user(), $batch);

        return response()->json(['ok' => true, 'batch' => $batch->fresh()]);
    }

    public function rebuild(ProjectTransferBatch $batch): JsonResponse
    {
        $this->authorizeOwner();
        $result = $this->transfers->rebuildBatchCaches(request()->user(), $batch);

        return response()->json(['ok' => true, 'cache_rebuild' => $result]);
    }

    public function preview(ProjectTransferRequest $request): JsonResponse
    {
        $this->authorizeOwner();
        $data = $request->validated();
        $source = Branch::query()->findOrFail($data['source_branch_id']);
        $target = Branch::query()->findOrFail($data['target_branch_id']);

        return response()->json($this->transfers->preview($request->user(), $source, $target, $data['project_ids'], $data['user_mapping'] ?? []));
    }

    public function store(ProjectTransferRequest $request): JsonResponse
    {
        $this->authorizeOwner();
        $data = $request->validated();
        $source = Branch::query()->findOrFail($data['source_branch_id']);
        $target = Branch::query()->findOrFail($data['target_branch_id']);
        if ($data['confirmation'] !== $target->name) {
            return response()->json(['message' => 'Ketik nama cabang tujuan untuk konfirmasi pemindahan.'], 422);
        }
        $result = $this->transfers->execute($request->user(), $source, $target, $data['project_ids'], $data['user_mapping'] ?? []);

        return response()->json($result, 201);
    }

    private function authorizeOwner(): void
    {
        abort_unless(request()->user()?->isSuperadmin() === true && ! (bool) session('impersonating_user_id'), 403);
    }
}
