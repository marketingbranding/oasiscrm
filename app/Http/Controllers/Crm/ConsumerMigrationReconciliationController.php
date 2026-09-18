<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsumerMigrationReconciliationRequest;
use App\Models\ConsumerMigrationReconciliation;
use App\Models\Customer;
use App\Models\Kavling;
use App\Services\ConsumerMigrationReconciliationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsumerMigrationReconciliationController extends Controller
{
    public function __construct(private readonly ConsumerMigrationReconciliationService $service) {}

    public function index(Request $request): View
    {
        $query = $this->service->visibleQuery($request->user());

        return view('crm.admin.marison-migrations.reconciliation-index', ['items' => $query->latest()->paginate(20)]);
    }

    public function show(Request $request, ConsumerMigrationReconciliation $reconciliation): View
    {
        $this->service->assertAccess($request->user(), $reconciliation);
        $reconciliation->load(['application.stageEvents', 'application.psjbs', 'application.bankProcesses', 'application.ppjbDevelopers', 'application.akadRecords', 'application.bastRecords', 'branch', 'project', 'customer', 'targetKavling']);
        $customers = Customer::query()->orderBy('name')->limit(500)->get(['id', 'name']);
        $kavlings = Kavling::query()->where('project_id', $reconciliation->project_id)->orderBy('name')->get();

        return view('crm.admin.marison-migrations.reconciliation-show', compact('reconciliation', 'customers', 'kavlings'));
    }

    public function resolve(ConsumerMigrationReconciliationRequest $request, ConsumerMigrationReconciliation $reconciliation)
    {
        $this->service->apply($reconciliation, $request->user(), $request->validated());

        return to_route('admin.marison-migrations.reconciliation.show', $reconciliation)->with('success', 'Rekonsiliasi berhasil diterapkan.');
    }
}
