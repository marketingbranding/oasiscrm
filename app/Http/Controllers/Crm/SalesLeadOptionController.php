<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SalesLead;
use App\Services\SalesLeadSheetOptionService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesLeadOptionController extends Controller
{
    public function __invoke(Request $request, Branch $branch, SalesLeadSheetOptionService $options, WorkspaceAccessService $access): JsonResponse
    {
        $this->authorize('create', SalesLead::class);
        abort_unless($access->canViewBranch($request->user(), $branch), 403);

        return response()->json(['options' => $options->forBranch($branch)]);
    }
}
