<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\SalesFeeReportRequest;
use App\Models\LeadMaster;
use App\Models\User;
use App\Services\SalesFeeReportService;
use Illuminate\View\View;

class SalesFeeReportController extends Controller
{
    public function __construct(private readonly SalesFeeReportService $reports) {}

    public function index(SalesFeeReportRequest $request): View
    {
        return view('crm.sales-fee-reports.index', $this->reports->summary($request->user(), $request->validated()));
    }

    public function show(SalesFeeReportRequest $request, User $salesUser, LeadMaster $project): View
    {
        return view('crm.sales-fee-reports.show', $this->reports->detail($request->user(), $salesUser, $project, $request->validated()));
    }

    public function print(SalesFeeReportRequest $request, User $salesUser, LeadMaster $project): View
    {
        return view('crm.sales-fee-reports.print', $this->reports->detail($request->user(), $salesUser, $project, $request->validated()));
    }
}
