<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\ProjectTransferRequest;
use App\Models\Branch;
use App\Models\LeadMaster;
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
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sheet_id']),
            'projects' => LeadMaster::query()->where('is_active', true)->with('assignedUsers:id,name')->orderBy('project_name')->get(['id', 'branch_id', 'project_name']),
        ]);
    }

    public function preview(ProjectTransferRequest $request): JsonResponse
    {
        $this->authorizeOwner();
        $data = $request->validated();
        $source = Branch::query()->findOrFail($data['source_branch_id']);
        $target = Branch::query()->findOrFail($data['target_branch_id']);

        return response()->json($this->transfers->preview($source, $target, $data['project_ids'], $data['user_mapping'] ?? []));
    }

    public function store(ProjectTransferRequest $request): JsonResponse
    {
        $this->authorizeOwner();
        $data = $request->validated();
        if ($request->input('confirmation') !== $request->input('target_branch_name')) {
            return response()->json(['message' => 'Ketik nama cabang tujuan untuk konfirmasi pemindahan.'], 422);
        }
        $source = Branch::query()->findOrFail($data['source_branch_id']);
        $target = Branch::query()->findOrFail($data['target_branch_id']);
        $result = $this->transfers->execute($request->user(), $source, $target, $data['project_ids'], $data['user_mapping'] ?? []);

        return response()->json($result, 201);
    }

    private function authorizeOwner(): void
    {
        abort_unless(request()->user()?->isSuperadmin() === true && ! (bool) session('impersonating_user_id'), 403);
    }
}
