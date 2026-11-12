<?php

namespace App\Http\Controllers\Crm;

use App\Exports\SalesFeeReportExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\SalesFeeReportRequest;
use App\Models\LeadMaster;
use App\Models\User;
use App\Services\SalesFeeReportService;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesFeeReportController extends Controller
{
    public function __construct(private readonly SalesFeeReportService $reports) {}

    public function index(SalesFeeReportRequest $request): View
    {
        return view('crm.sales-fee-reports.index', $this->reports->summary($request->user(), $request->validated()));
    }

    public function export(SalesFeeReportRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $report = $this->reports->summary($request->user(), $filters);
        $dateFrom = CarbonImmutable::parse($filters['date_from'])->toDateString();
        $dateTo = CarbonImmutable::parse($filters['date_to'])->toDateString();

        return SalesFeeReportExport::toBrowser(
            $report['rows'],
            $report,
            "laporan-fee-sales-{$dateFrom}-sampai-{$dateTo}.xlsx",
        );
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
