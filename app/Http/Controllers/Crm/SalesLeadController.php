<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreSalesLeadRequest;
use App\Http\Requests\Crm\UpdateSalesLeadRequest;
use App\Models\LeadSource;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\CollaborationNotificationService;
use App\Services\OptimisticLockService;
use App\Services\PresenceService;
use App\Services\SalesLeadDuplicateService;
use App\Services\SalesLeadService;
use App\Services\WorkspaceAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SalesLeadController extends Controller
{
    public function __construct(
        private readonly SalesLeadService $leads,
        private readonly SalesLeadDuplicateService $duplicates,
        private readonly OptimisticLockService $optimisticLock,
        private readonly CollaborationNotificationService $notifications,
        private readonly PresenceService $presence,
        private readonly WorkspaceAccessService $workspaceAccess,
    ) {}

    public function create(Request $request)
    {
        $this->authorize('create', SalesLead::class);

        return redirect()->route('sales-pocketbook.index', ['input' => 1]);
    }

    public function store(StoreSalesLeadRequest $request)
    {
        $lead = $this->leads->create($request->safe()->except('expected_updated_at'), $request->user());
        $duplicates = $this->duplicates->matches($request->user(), $lead->phone, $lead->id);

        $redirect = $request->input('submit_action') === 'add_another'
            ? route('sales-pocketbook.index', [
                'input' => 1,
                'lead_date' => $lead->lead_date->toDateString(),
                'project_id' => $lead->project_id,
            ])
            : route('sales-pocketbook.index');

        $response = redirect($redirect)->with('success', 'Lead berhasil disimpan.');
        if ($duplicates->isNotEmpty()) {
            $response->with('duplicate_warning', $duplicates->all());
        }

        return $response;
    }

    public function edit(SalesLead $salesLead)
    {
        $this->authorize('update', $salesLead);
        $user = request()->user();
        $branchIds = $this->workspaceAccess->accessibleBranchIds($user);

        return view('crm.sales-pocketbook.edit', [
            'lead' => $salesLead->load(['branch', 'project', 'sales', 'leadSource']),
            'branches' => $this->workspaceAccess->accessibleBranches($user),
            'projects' => $this->workspaceAccess->accessibleProjects($user)->load('assignedUsers:id'),
            'salesUsers' => User::query()->where('is_active', true)
                ->whereHas('role', fn (Builder $query) => $query->where('slug', 'sales'))
                ->whereHas('assignedProjects', fn (Builder $query) => $query->whereIn('branch_id', $branchIds))
                ->when($user->hasRole('sales'), fn (Builder $query) => $query->whereKey($user->id))
                ->with('assignedProjects:id,branch_id')->orderBy('name')->get(['id', 'name', 'branch_id']),
            'leadSources' => LeadSource::query()->where('is_active', true)
                ->when($salesLead->lead_source_id, fn (Builder $query) => $query->orWhere('id', $salesLead->lead_source_id))
                ->orderBy('name')->get(),
            'optimisticToken' => $this->optimisticLock->token($salesLead),
        ]);
    }

    public function update(UpdateSalesLeadRequest $request, SalesLead $salesLead)
    {
        $data = $request->safe()->except('expected_updated_at');
        $result = $this->optimisticLock->execute($request, $salesLead, $request->input('expected_updated_at'), function (SalesLead $current) use ($request, $data) {
            $this->authorize('update', $current);

            return $this->leads->update($current, $data, $request->user());
        });
        if ($result instanceof Response) {
            return $result;
        }

        $this->presence->clearEditing($request->user(), $result, $request->input('presence_session_key'));
        $this->notifications->recordUpdated($result, $request->user(), route('sales-pocketbook.index'));

        if ($request->expectsJson()) {
            $result->load(['branch:id,name', 'project:id,project_name', 'sales:id,name', 'leadSource:id,name,is_active']);

            return response()->json([
                'ok' => true,
                'lead' => [
                    'id' => $result->id,
                    'lead_date' => $result->lead_date->toDateString(),
                    'customer_name' => $result->customer_name,
                    'phone' => $result->phone,
                    'branch' => $result->branch?->name,
                    'project' => $result->project?->project_name,
                    'sales' => $result->sales?->name,
                    'source' => $result->source_name_snapshot ?: $result->leadSource?->name,
                    'source_active' => (bool) $result->leadSource?->is_active,
                ],
                'updated_at' => $this->optimisticLock->token($result),
            ]);
        }

        return redirect()->route('sales-pocketbook.index')->with('success', 'Lead berhasil diperbarui.');
    }

    public function duplicatePhone(Request $request)
    {
        $this->authorize('viewAny', SalesLead::class);
        $data = $request->validate(['phone' => ['required', 'string', 'max:50'], 'except_id' => ['nullable', 'integer']]);

        return response()->json(['matches' => $this->duplicates->matches($request->user(), $data['phone'], $data['except_id'] ?? null)]);
    }
}
