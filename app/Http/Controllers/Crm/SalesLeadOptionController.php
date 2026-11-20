<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SalesLead;
use App\Services\OrganizationScopeService;
use App\Services\SalesLeadSheetOptionService;
use App\Services\WorkspaceAccessService;
use App\Support\FeatureFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesLeadOptionController extends Controller
{
    public function __invoke(
        Request $request,
        Branch $branch,
        WorkspaceAccessService $access,
        OrganizationScopeService $organizationScope,
    ): JsonResponse {
        $this->authorize('create', SalesLead::class);
        abort_unless($access->canViewBranch($request->user(), $branch), 403);
        if (FeatureFlag::enabled('org_hierarchical_monitoring')) {
            abort_unless(in_array($branch->id, $organizationScope->branchIds($request->user(), 'sales_pocketbook', 'view'), true), 403);
        }

        if (! config('services.google_sheets.sales_lead_sync_enabled')) {
            return response()->json(['message' => 'Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.'], 503);
        }

        return response()->json(['options' => app(SalesLeadSheetOptionService::class)->forBranch($branch)]);
    }
}
