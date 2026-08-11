<?php

namespace App\Http\Controllers\Crm;

use App\Enums\SalesLeadStatus;
use App\Exports\CoordinatorSalesLeadExport;
use App\Http\Controllers\Controller;
use App\Models\Promo;
use App\Services\CoordinatorLeadPushService;
use App\Services\CoordinatorLeadTeamService;
use App\Services\CoordinatorSalesMonitoringService;
use App\Support\SalesLeadMasterData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CoordinatorSalesLeadWorkspaceController extends Controller
{
    public function __construct(
        private readonly CoordinatorLeadTeamService $teams,
        private readonly CoordinatorLeadPushService $pushService,
        private readonly CoordinatorSalesLeadExport $export,
        private readonly CoordinatorSalesMonitoringService $monitoring,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'period' => ['nullable', 'in:today,week,month,custom'],
            'date_from' => ['nullable', 'required_if:period,custom', 'date'],
            'date_to' => ['nullable', 'required_if:period,custom', 'date', 'after_or_equal:date_from'],
            'sales_id' => ['nullable', 'integer'],
        ]);
        $data = $this->monitoring->resolve($request->user(), $filters);
        $data['projectsBySales'] = $data['salesUsers']->mapWithKeys(fn ($sales) => [
            (string) $sales->id => $sales->assignedProjects->map(fn ($project) => [
                'id' => (string) $project->id,
                'name' => $project->project_name,
                'branch_id' => (string) $project->branch_id,
                'branch_name' => $project->branch?->name,
            ])->values(),
        ]);
        $data['sources'] = SalesLeadMasterData::SOURCES;
        $data['channels'] = SalesLeadMasterData::CHANNELS;
        $data['activities'] = SalesLeadMasterData::ACTIVITIES;
        $data['promos'] = Promo::query()->where('is_active', true)->orderBy('name')->pluck('name');
        $data['statuses'] = SalesLeadStatus::cases();
        $data['canSync'] = $request->user()->hasPermission('sales_pocketbook.sync');

        return view('crm.sales-pocketbook.coordinator-leads', $data);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($this->teams->isCoordinator($user), 403);
        abort_unless($user->hasPermission('sales_pocketbook.export'), 403);

        $filters = $request->validate([
            'period' => ['nullable', 'in:today,week,month,custom'],
            'date_from' => ['nullable', 'required_if:period,custom', 'date'],
            'date_to' => ['nullable', 'required_if:period,custom', 'date', 'after_or_equal:date_from'],
            'sales_id' => ['nullable', 'integer'],
        ]);
        $data = $this->monitoring->resolve($user, $filters, false);

        return $this->export->toBrowser($data['exportData'], 'lead-tim-sales-'.now()->format('Ymd-His').'.xlsx');
    }

    public function push(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->teams->isCoordinator($user), 403);
        abort_unless($user->hasPermission('sales_pocketbook.sync'), 403);

        $result = $this->pushService->push($user);
        $message = "{$result['synced']} lead tersinkron";

        return back()->with($result['failed'] > 0 ? 'warning' : 'success', $result['failed'] > 0
            ? $message.", {$result['failed']} gagal."
            : $message.'.');
    }
}
