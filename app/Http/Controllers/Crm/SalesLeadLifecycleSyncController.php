<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SalesLeadLifecycleReconciliationItem;
use App\Models\SalesLeadLifecycleSyncStatus;
use App\Services\OrganizationScopeService;
use App\Services\SalesLeadLifecycleSyncService;
use App\Services\SyncResponseService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesLeadLifecycleSyncController extends Controller
{
    public function __construct(
        private readonly OrganizationScopeService $organizationScope,
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly SyncResponseService $responses,
    ) {}

    public function sync(Request $request, SalesLeadLifecycleSyncService $service): JsonResponse
    {
        $branch = $this->authorizedBranch($request, 'sales_pocketbook.sync');
        $result = $service->sync($branch, $request->user());
        $status = SalesLeadLifecycleSyncStatus::query()->where('branch_id', $branch->id)->first();
        $response = $this->responses->make('sales-lead-lifecycle', $this->scope($branch), $status, $result);

        return response()->json($response, $result['ok'] ? 200 : ($result['status'] === 'syncing' ? 409 : 422));
    }

    public function status(Request $request): JsonResponse
    {
        $branch = $this->authorizedBranch($request, 'sales_pocketbook.sync');

        $status = SalesLeadLifecycleSyncStatus::query()->where('branch_id', $branch->id)->first();

        return response()->json($this->responses->make('sales-lead-lifecycle', $this->scope($branch), $status));
    }

    public function reconciliations(Request $request): JsonResponse
    {
        $branch = $this->authorizedBranch($request, 'sales_pocketbook.reconcile');
        $items = SalesLeadLifecycleReconciliationItem::query()
            ->where('branch_id', $branch->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->latest('id')
            ->paginate(50);

        return response()->json($items);
    }

    private function authorizedBranch(Request $request, string $permission): Branch
    {
        $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id']]);
        $user = $request->user();
        abort_unless($user->hasPermission($permission), 403);
        $branch = Branch::query()->whereKey($request->integer('branch_id'))->where('is_active', true)->firstOrFail();
        $allowedBranchIds = $this->organizationScope->branchIds($user, 'sales_pocketbook', 'manage');
        abort_unless(in_array((int) $branch->id, $allowedBranchIds, true), 403);
        abort_unless($this->workspaceAccess->canViewBranch($user, $branch), 403);
        abort_unless($this->workspaceAccess->canSyncBranch($user, $branch), 403);

        return $branch;
    }

    private function scope(Branch $branch): array
    {
        return ['type' => 'branch', 'id' => $branch->id, 'name' => $branch->name];
    }
}
