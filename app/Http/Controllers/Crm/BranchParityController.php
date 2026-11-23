<?php

namespace App\Http\Controllers\Crm;

use App\Exports\BranchParityReportExport;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ConsumerParitySnapshot;
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
        $snapshot = $branch ? ConsumerParitySnapshot::query()->where('branch_id', $branch->id)->latest('checked_at')->first() : null;
        $report = null;

        return view('crm.branch-parity.index', compact('branches', 'branch', 'report', 'snapshot'));
    }

    public function run(Request $request, Branch $branch): View
    {
        abort_unless($request->user()->isSuperadmin(), 403);
        $this->service->report($branch);

        return redirect()->route('admin.branch-parity.index', ['branch' => $branch->id]);
    }

    public function export(Request $request, Branch $branch): BinaryFileResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        return BranchParityReportExport::toBrowser($this->service->report($branch));
    }
}
