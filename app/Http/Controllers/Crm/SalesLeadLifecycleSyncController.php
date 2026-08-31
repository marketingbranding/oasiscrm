<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SalesLeadLifecycleReconciliationItem;
use App\Models\SalesLeadLifecycleSyncStatus;
use App\Services\OrganizationScopeService;
use App\Services\SalesLeadBridgeModeService;
use App\Services\SalesLeadBridgeService;
use App\Services\SalesLeadSyncService;
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

    public function sync(Request $request): JsonResponse
    {
        abort_if($request->user()->hasPrimaryRole(['sales', 'sales_coordinator', 'supervisor']), 403);
        $branch = $this->authorizedSyncBranch($request);
        if (! config('services.google_sheets.sales_lead_sync_enabled')) {
            return response()->json(['message' => 'Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.'], 503);
        }

        $result = app(SalesLeadBridgeModeService::class)->isPullEnabled($branch)
            ? app(SalesLeadBridgeService::class)->pull($branch, $request->user())
            : app(SalesLeadSyncService::class)->sync($branch, $request->user());
        $status = $this->personalOrBranchStatus($branch, $request);
        $response = $this->responses->make('sales-lead-lifecycle', $this->scope($branch), $status, $result);

        return response()->json($response, $result['ok'] ? 200 : ($result['status'] === 'syncing' ? 409 : 422));
    }

    public function bridgeSync(Request $request): JsonResponse
    {
        $branch = $this->authorizedSyncBranch($request);
        if (! config('services.google_sheets.sales_lead_sync_enabled')) {
            return response()->json(['message' => 'Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.'], 503);
        }
        $result = app(SalesLeadBridgeService::class)->pull($branch, $request->user());
        $status = $this->personalOrBranchStatus($branch, $request);
        $response = $this->responses->make('sales-lead-lifecycle', $this->scope($branch), $status, $result);

        return response()->json($response, $result['ok'] ? 200 : ($result['status'] === 'syncing' ? 409 : 422));
    }

    public function status(Request $request): JsonResponse
    {
        abort_if($request->user()->hasPrimaryRole(['sales', 'sales_coordinator', 'supervisor']), 403);
        $branch = $this->viewableBranch($request);
        if (! config('services.google_sheets.sales_lead_sync_enabled')) {
            return response()->json(['message' => 'Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.'], 503);
        }

        $status = $this->personalOrBranchStatus($branch, $request);

        return response()->json($this->responses->make('sales-lead-lifecycle', $this->scope($branch), $status));
    }

    public function reconciliations(Request $request): JsonResponse
    {
        abort_if($request->user()->hasPrimaryRole('supervisor'), 403);
        $branch = $this->authorizedBranch($request, 'sales_pocketbook.reconcile');
        $items = SalesLeadLifecycleReconciliationItem::query()
            ->where('branch_id', $branch->id)
            ->when($request->filled('scope'), function ($query) use ($request) {
                $scope = $request->string('scope')->toString();
                if ($scope === 'lead') {
                    $query->whereIn('entity_type', ['lead', 'lead_status']);
                } elseif ($scope === 'lifecycle') {
                    $query->whereNotIn('entity_type', ['lead', 'lead_status']);
                }
            })
            ->when($request->filled('entity_type'), fn ($query) => $query->where('entity_type', $request->string('entity_type')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->latest('id')
            ->paginate(50);

        $items->getCollection()->transform(function (SalesLeadLifecycleReconciliationItem $item): mixed {
            if (! in_array($item->issue_code, SalesLeadBridgeService::BRIDGE_ISSUES, true)) {
                return $item;
            }
            $metadata = $item->metadata ?? [];

            return [
                'id' => $item->id,
                'code' => $item->issue_code,
                'status' => $item->status,
                'field_names' => $metadata['field_names'] ?? [],
                'remote_row_number' => $metadata['remote_row_number'] ?? null,
            ];
        });

        return response()->json($items);
    }

    private function personalOrBranchStatus(Branch $branch, Request $request): ?SalesLeadLifecycleSyncStatus
    {
        return SalesLeadLifecycleSyncStatus::query()
            ->where('branch_id', $branch->id)
            ->whereIn('scope', [SalesLeadSyncService::scopeFor($request->user()), SalesLeadBridgeService::SCOPE])
            ->latest('updated_at')
            ->first();
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

    private function authorizedSyncBranch(Request $request): Branch
    {
        $branch = $this->viewableBranch($request);
        $user = $request->user();
        abort_unless($user->hasPermission('sales_pocketbook.sync'), 403);
        abort_unless(in_array((int) $branch->id, $this->organizationScope->branchIds($user, 'sales_pocketbook', 'manage'), true), 403);

        if ($user->isSales()) {
            abort_unless($this->workspaceAccess->accessibleProjectsQuery($user)->where('branch_id', $branch->id)->exists(), 403);
        } else {
            abort_unless($this->workspaceAccess->canSyncBranch($user, $branch), 403);
        }

        return $branch;
    }

    private function viewableBranch(Request $request): Branch
    {
        $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id']]);
        $user = $request->user();
        $branch = Branch::query()->whereKey($request->integer('branch_id'))->where('is_active', true)->firstOrFail();
        abort_unless(in_array((int) $branch->id, $this->organizationScope->branchIds($user, 'sales_pocketbook'), true), 403);
        abort_unless($this->workspaceAccess->canViewBranch($user, $branch), 403);

        return $branch;
    }

    private function scope(Branch $branch): array
    {
        return ['type' => 'branch', 'id' => $branch->id, 'name' => $branch->name];
    }
}
