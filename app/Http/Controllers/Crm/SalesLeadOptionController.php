<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SalesLead;
use App\Services\CoordinatorLeadTeamService;
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
        CoordinatorLeadTeamService $coordinatorTeam,
    ): JsonResponse {
        $this->authorize('create', SalesLead::class);
        abort_unless($access->canViewBranch($request->user(), $branch), 403);
        if (FeatureFlag::enabled('org_hierarchical_monitoring')) {
            abort_unless(in_array($branch->id, $organizationScope->branchIds($request->user(), 'sales_pocketbook', 'view'), true), 403);
        }

        if (! config('services.google_sheets.sales_lead_sync_enabled')) {
            return response()->json(['message' => 'Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.'], 503);
        }

        $options = app(SalesLeadSheetOptionService::class)->forBranch($branch);
        if (FeatureFlag::enabled('org_hierarchical_monitoring') && $coordinatorTeam->isCoordinator($request->user())) {
            $salesNames = $coordinatorTeam->currentSales($request->user())
                ->filter(fn ($sales) => (int) $sales->branch_id === (int) $branch->id)
                ->pluck('name')
                ->all();
            $options['sales'] = array_values(array_intersect($options['sales'], $salesNames));
        }

        return response()->json(['options' => $options]);
    }
}
