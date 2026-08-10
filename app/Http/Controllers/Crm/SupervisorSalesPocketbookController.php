<?php

namespace App\Http\Controllers\Crm;

use App\Exports\SupervisorSalesAgendaExport;
use App\Exports\SupervisorSalesLeadExport;
use App\Http\Controllers\Controller;
use App\Services\SupervisorSalesMonitoringService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupervisorSalesPocketbookController extends Controller
{
    public function __construct(private readonly SupervisorSalesMonitoringService $monitoring) {}

    public function index(Request $request): View
    {
        $this->authorizeSupervisor($request);

        return view('crm.sales-pocketbook.supervisor-monitoring', $this->monitoring->resolve($request->user(), $this->validatedFilters($request)));
    }

    public function agendaExport(Request $request): BinaryFileResponse
    {
        $this->authorizeSupervisor($request);
        abort_unless($request->user()->hasPermission('sales_pocketbook.export'), 403);
        $data = $this->monitoring->exportData($request->user(), $this->validatedFilters($request));

        return SupervisorSalesAgendaExport::toBrowser($data['agendas'], $data['coordinatorNamesBySalesId'], $this->filename('agenda', $data));
    }

    public function leadExport(Request $request): BinaryFileResponse
    {
        $this->authorizeSupervisor($request);
        abort_unless($request->user()->hasPermission('sales_pocketbook.export'), 403);
        $data = $this->monitoring->exportData($request->user(), $this->validatedFilters($request));

        return SupervisorSalesLeadExport::toBrowser($data['leads'], $data['coordinatorNamesBySalesId'], $this->filename('lead', $data));
    }

    private function authorizeSupervisor(Request $request): void
    {
        abort_unless($this->monitoring->isSupervisor($request->user()), 403);
        abort_unless($request->user()->hasScopedPermission('sales_pocketbook'), 403);
    }

    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'period' => ['nullable', 'in:today,week,month,custom'],
            'date_from' => ['nullable', 'required_if:period,custom', 'date'],
            'date_to' => ['nullable', 'required_if:period,custom', 'date', 'after_or_equal:date_from'],
            'coordinator_id' => ['nullable', 'integer', 'min:1'],
            'sales_id' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    private function filename(string $type, array $data): string
    {
        return "buku-saku-spv-{$type}-{$data['filters']['date_from']}-{$data['filters']['date_to']}.xlsx";
    }
}
