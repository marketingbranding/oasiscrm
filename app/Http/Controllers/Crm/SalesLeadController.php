<?php

namespace App\Http\Controllers\Crm;

use App\Enums\SalesLeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreSalesLeadRequest;
use App\Http\Requests\Crm\UpdateSalesLeadRequest;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\CollaborationNotificationService;
use App\Services\OptimisticLockService;
use App\Services\PresenceService;
use App\Services\PromoOptionService;
use App\Services\SalesLeadDuplicateService;
use App\Services\SalesLeadService;
use App\Services\WorkspaceAccessService;
use App\Support\SalesLeadMasterData;
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
        private readonly PromoOptionService $promoOptions,
    ) {}

    public function create(Request $request)
    {
        $this->authorize('create', SalesLead::class);

        return $request->user()->isSales()
            ? redirect()->route('sales-agendas.index', ['tab' => 'leads', 'input' => 1])
            : redirect()->route('sales-pocketbook.index', ['input' => 1]);
    }

    public function store(StoreSalesLeadRequest $request)
    {
        $lead = $this->leads->create($request->safe()->except('expected_updated_at'), $request->user());
        $duplicates = $this->duplicates->matches($request->user(), $lead->phone, $lead->id);

        if ($request->user()->isSales()) {
            $redirect = route('sales-agendas.index', array_filter([
                'tab' => 'leads',
                'input' => $request->input('submit_action') === 'add_another' ? 1 : null,
                'lead_date' => $request->input('submit_action') === 'add_another' ? $lead->lead_date->toDateString() : null,
                'project_id' => $request->input('submit_action') === 'add_another' ? $lead->project_id : null,
            ]));
        } else {
            $redirect = $request->input('submit_action') === 'add_another'
                ? route('sales-pocketbook.index', [
                    'input' => 1,
                    'lead_date' => $lead->lead_date->toDateString(),
                    'project_id' => $lead->project_id,
                ])
                : route('sales-pocketbook.index', $lead->current_status === SalesLeadStatus::SiteVisit
                    ? ['lifecycle_action' => 'site_visit', 'lead' => $lead->id]
                    : []);
        }

        $response = redirect($redirect)->with('success', 'Lead berhasil disimpan.');
        if ($duplicates->isNotEmpty()) {
            $response->with('duplicate_warning', $duplicates->all());
        }

        return $response;
    }

    public function show(SalesLead $salesLead)
    {
        $this->authorize('viewSiteVisit', $salesLead);
        $salesLead->load([
            'branch',
            'project',
            'sales',
            'statusHistories' => fn ($query) => $query->latest('id'),
            'siteVisits' => fn ($query) => $query->latest('id'),
        ]);

        return view('crm.sales-pocketbook.lead-detail', ['lead' => $salesLead]);
    }

    public function edit(SalesLead $salesLead)
    {
        $this->authorize('update', $salesLead);
        $user = request()->user();
        $branchIds = $this->workspaceAccess->accessibleBranchIds($user);
        $salesLead->loadCount('comments');

        return view('crm.sales-pocketbook.edit', [
            'lead' => $salesLead->load(['branch', 'project', 'sales', 'leadSource']),
            'branches' => $this->workspaceAccess->accessibleBranches($user),
            'projects' => $this->workspaceAccess->accessibleProjects($user)->load('assignedUsers:id'),
            'salesUsers' => User::query()->where('is_active', true)
                ->whereHas('role', fn (Builder $query) => $query->where('slug', 'sales'))
                ->whereHas('assignedProjects', fn (Builder $query) => $query->whereIn('branch_id', $branchIds))
                ->when($user->isSales(), fn (Builder $query) => $query->whereKey($user->id))
                ->with('assignedProjects:id,branch_id')->orderBy('name')->get(['id', 'name', 'branch_id']),
            'optimisticToken' => $this->optimisticLock->token($salesLead),
            'sources' => SalesLeadMasterData::SOURCES,
            'channels' => SalesLeadMasterData::CHANNELS,
            'activities' => SalesLeadMasterData::ACTIVITIES,
            'promos' => $this->promoOptions->availableForBranchAndDate(
                (int) $salesLead->project->branch_id,
                $salesLead->lead_date,
                $salesLead->id_promo,
            ),
            'promoOptionsEndpoint' => $user->hasPrimaryRole('sales_coordinator')
                ? route('coordinator-leads.promo-options', ['project' => 'PROJECT_ID', 'lead_id' => $salesLead->id])
                : null,
        ]);
    }

    public function update(UpdateSalesLeadRequest $request, SalesLead $salesLead)
    {
        $data = $request->safe()->except(['expected_updated_at', 'operation_uuid']);
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
            $result->load(['branch:id,name', 'project:id,project_name', 'sales:id,name', 'leadSource:id,name']);

            return response()->json([
                'ok' => true,
                'message' => 'Lead berhasil diperbarui.',
                'lead' => [
                    'id' => $result->id,
                    'lead_date' => $result->lead_date->toDateString(),
                    'customer_name' => $result->customer_name,
                    'phone' => $result->phone,
                    'branch' => $result->branch?->name,
                    'project' => $result->project?->project_name,
                    'sales' => $result->sales?->name,
                    'source' => $result->effective_source,
                    'platform' => $result->platform,
                    'campaign_name' => $result->campaign_name,
                    'id_promo' => $result->id_promo,
                    'current_status' => $result->current_status->value,
                    'current_status_label' => $result->current_status->label(),
                ],
                'updated_at' => $this->optimisticLock->token($result),
            ]);
        }

        return redirect()->route($request->user()->isSales() ? 'sales-agendas.index' : 'sales-pocketbook.index', $request->user()->isSales() ? ['tab' => 'leads'] : [])->with('success', 'Lead berhasil diperbarui.');
    }

    public function destroy(Request $request, SalesLead $salesLead)
    {
        $this->authorize('delete', $salesLead);

        try {
            $this->leads->delete($salesLead, $request->user());
        } catch (\DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route($request->user()->isSales() ? 'sales-agendas.index' : 'sales-pocketbook.index', $request->user()->isSales() ? ['tab' => 'leads'] : [])->with('success', 'Lead berhasil dihapus dari data operasional.');
    }

    public function duplicatePhone(Request $request)
    {
        $this->authorize('viewAny', SalesLead::class);
        $data = $request->validate(['phone' => ['required', 'string', 'max:50'], 'except_id' => ['nullable', 'integer']]);

        return response()->json(['matches' => $this->duplicates->matches($request->user(), $data['phone'], $data['except_id'] ?? null)]);
    }
}
