<?php

namespace App\Http\Controllers\Crm;

use App\Exports\BranchParityReportExport;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\BranchParityReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BranchParityController extends Controller
{
    public function __construct(private readonly BranchParityReportService $service) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->isSuperadmin(), 403);
        $branches = Branch::query()->orderBy('name')->get();
        $branch = $request->filled('branch') ? $branches->firstWhere('id', $request->integer('branch')) : null;
        abort_if($request->filled('branch') && ! $branch, 404);
        $report = $branch ? $this->service->report($branch) : null;

        return view('crm.branch-parity.index', compact('branches', 'branch', 'report'));
    }

    public function export(Request $request, Branch $branch): BinaryFileResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        return BranchParityReportExport::toBrowser($this->service->report($branch));
    }
}
