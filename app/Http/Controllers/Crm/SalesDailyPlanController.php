<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\CompleteSalesDailyPlanItemRequest;
use App\Http\Requests\Crm\ReorderSalesDailyPlanRequest;
use App\Http\Requests\Crm\ReviseSalesDailyPlanRequest;
use App\Http\Requests\Crm\StoreSalesDailyPlanAgendaRequest;
use App\Http\Requests\Crm\UpdateSalesDailyPlanAgendaRequest;
use App\Models\ContentItem;
use App\Models\SalesDailyPlan;
use App\Models\SalesDailyPlanItem;
use App\Models\SalesLeadTarget;
use App\Models\User;
use App\Services\OptimisticLockService;
use App\Services\SalesDailyPlanScheduleService;
use App\Services\SalesDailyPlanService;
use App\Services\SalesLeadTargetService;
use App\Services\SalesTeamScopeService;
use App\Services\WorkspaceAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class SalesDailyPlanController extends Controller
{
    public function __construct(
        private readonly SalesDailyPlanService $plans,
        private readonly SalesDailyPlanScheduleService $schedule,
        private readonly SalesLeadTargetService $targets,
        private readonly OptimisticLockService $optimisticLock,
        private readonly WorkspaceAccessService $workspace,
        private readonly SalesTeamScopeService $salesTeam,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $this->authorize('viewAny', SalesDailyPlan::class);
        $date = CarbonImmutable::parse($request->query('date', today()->toDateString()));
        $plans = $this->visiblePlans($user)->whereDate('plan_date', $date->toDateString())
            ->with(['sales:id,name', 'branch:id,name', 'agendas' => fn ($query) => $query->with('owner:id,name')])
            ->orderBy('sales_user_id')->get();
        $selectedPlan = $user->isSales()
            ? $plans->firstWhere('sales_user_id', $user->id)
            : ($request->filled('sales_id') ? $plans->firstWhere('sales_user_id', $request->integer('sales_id')) : $plans->first());
        $schedule = $selectedPlan ? $this->schedule->calculate($selectedPlan) : ['total_minutes' => 0, 'remaining_minutes' => 420, 'items' => [], 'end_time' => null];
        $canViewTargets = $user->can('viewAny', SalesLeadTarget::class);
        $summary = $canViewTargets
            ? $this->targets->summary($user, $date->year, $date->month, $user->isSales() ? $user->id : null)
            : collect();
        $historicalAgendas = $this->historicalAgendas($user, $date);
        $canManageTargets = $user->can('create', SalesLeadTarget::class);

        return view('crm.sales-pocketbook.daily-plans', compact('date', 'plans', 'selectedPlan', 'schedule', 'summary', 'historicalAgendas', 'canViewTargets', 'canManageTargets'));
    }

    public function store(Request $request): Response
    {
        if ($request->has('work_date') || $request->has('activities')) {
            abort_unless($request->user()?->isSales(), 403);
            $data = $request->validate([
                'work_date' => ['required', 'date_format:Y-m-d'],
                'activities' => ['required', 'array', 'min:1'],
                'activities.*.activity' => ['required', 'string', 'max:255'],
                'activities.*.detail' => ['nullable', 'string', 'max:5000'],
                'activities.*.duration_minutes' => ['required', 'integer', 'min:1', 'max:420'],
            ]);
            $date = CarbonImmutable::createFromFormat('Y-m-d', $data['work_date'], config('app.timezone'));
            $this->plans->saveDraft($request->user(), $date, $data['activities']);

            return to_route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $data['work_date']])->with('success', 'Draft Daily Plan berhasil disimpan.');
        }

        $this->authorize('create', SalesDailyPlan::class);
        $data = $request->validate(['plan_date' => ['required', 'date_format:Y-m-d']]);
        try {
            $plan = $this->plans->create($request->user(), $data['plan_date']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['plan_date' => $exception->getMessage()]);
        }

        return redirect()->route('sales-daily-plans.index', ['date' => ($plan->plan_date ?? $plan->work_date)->toDateString()])->with('success', 'Daily Plan siap diisi.');
    }

    public function storeAgenda(StoreSalesDailyPlanAgendaRequest $request, SalesDailyPlan $dailyPlan): Response
    {
        $data = $request->validated();
        $result = $this->mutate($request, $dailyPlan, $data['expected_updated_at'], fn (SalesDailyPlan $plan) => $this->plans->addAgenda($plan, $request->user(), $data));

        return $result instanceof Response
            ? $result
            : redirect()->route('sales-daily-plans.index', ['date' => ($dailyPlan->plan_date ?? $dailyPlan->work_date)->toDateString()])->with('success', 'Agenda planning ditambahkan.');
    }

    public function updateAgenda(UpdateSalesDailyPlanAgendaRequest $request, SalesDailyPlan $dailyPlan, ContentItem $agenda): Response
    {
        $data = $request->validated();
        $result = $this->mutate($request, $dailyPlan, $data['expected_updated_at'], fn (SalesDailyPlan $plan) => $this->plans->updateAgenda($plan, $agenda, $request->user(), $data));

        return $result instanceof Response
            ? $result
            : redirect()->route('sales-daily-plans.index', ['date' => ($dailyPlan->plan_date ?? $dailyPlan->work_date)->toDateString()])->with('success', 'Planning Agenda diperbarui.');
    }

    public function destroyAgenda(Request $request, SalesDailyPlan $dailyPlan, ContentItem $agenda): Response
    {
        $this->authorize('update', $dailyPlan);
        $data = $request->validate(['expected_updated_at' => ['required', 'string', 'max:60']]);
        $result = $this->mutate($request, $dailyPlan, $data['expected_updated_at'], fn (SalesDailyPlan $plan) => $this->plans->deleteAgenda($plan, $agenda, $request->user()));

        return $result instanceof Response
            ? $result
            : redirect()->route('sales-daily-plans.index', ['date' => ($dailyPlan->plan_date ?? $dailyPlan->work_date)->toDateString()])->with('success', 'Agenda planning dihapus.');
    }

    public function reorder(ReorderSalesDailyPlanRequest $request, SalesDailyPlan $dailyPlan): Response
    {
        $data = $request->validated();
        $result = $this->mutate($request, $dailyPlan, $data['expected_updated_at'], fn (SalesDailyPlan $plan) => $this->plans->reorder($plan, $data['agenda_ids'], $request->user()));

        return $result instanceof Response
            ? $result
            : redirect()->route('sales-daily-plans.index', ['date' => ($dailyPlan->plan_date ?? $dailyPlan->work_date)->toDateString()])->with('success', 'Urutan Agenda diperbarui.');
    }

    public function submit(Request $request, ?SalesDailyPlan $dailyPlan = null): Response
    {
        if ($request->has('work_date')) {
            abort_unless($request->user()?->isSales(), 403);
            $data = $request->validate(['work_date' => ['required', 'date_format:Y-m-d']]);
            $date = CarbonImmutable::createFromFormat('Y-m-d', $data['work_date'], config('app.timezone'));
            $this->plans->submit($request->user(), $date);

            return to_route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $data['work_date']])->with('success', 'Daily Plan berhasil disubmit dan dikunci.');
        }

        abort_unless($dailyPlan, 404);
        $this->authorize('submit', $dailyPlan);
        $data = $request->validate(['expected_updated_at' => ['required', 'string', 'max:60']]);
        $result = $this->mutate($request, $dailyPlan, $data['expected_updated_at'], fn (SalesDailyPlan $plan) => $this->plans->submit($plan, $request->user()));

        return $result instanceof Response
            ? $result
            : redirect()->route('sales-daily-plans.index', ['date' => ($dailyPlan->plan_date ?? $dailyPlan->work_date)->toDateString()])->with('success', 'Daily Plan dikirim dengan total 420 menit.');
    }

    public function complete(CompleteSalesDailyPlanItemRequest $request, SalesDailyPlanItem $item): Response
    {
        $this->plans->completeItem($request->user(), $item, $request->validated('activity_result'));

        return back()->with('success', 'Hasil aktivitas berhasil disimpan.');
    }

    public function revise(ReviseSalesDailyPlanRequest $request, SalesDailyPlan $plan): Response
    {
        $this->plans->revise($request->user(), $plan, $request->validated('activities'));

        return back()->with('success', 'Planning anggota Team berhasil direvisi dan Sales akan menerima pemberitahuan.');
    }

    public function storeTarget(Request $request): Response
    {
        $this->authorize('create', SalesLeadTarget::class);
        $data = $request->validate([
            'sales_user_id' => ['required', 'integer', 'exists:users,id'],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2200'],
            'period_month' => ['required', 'integer', 'between:1,12'],
            'lead_target' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);
        try {
            $this->targets->upsert($request->user(), $data);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['sales_user_id' => $exception->getMessage()]);
        }

        return back()->with('success', 'Target lead berhasil disimpan.');
    }

    private function mutate(Request $request, SalesDailyPlan $plan, string $expected, callable $callback): mixed
    {
        try {
            return $this->optimisticLock->execute($request, $plan, $expected, $callback);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['plan' => $exception->getMessage()]);
        }
    }

    private function visiblePlans(User $user): Builder
    {
        $query = SalesDailyPlan::query();
        if ($user->hasPermission('sales_daily_plans.view_all')) {
            return $query;
        }
        if ($user->isSales()) {
            return $query->where('sales_user_id', $user->id);
        }
        if ($user->hasPrimaryRole('sales_coordinator')) {
            return $query->whereIn('sales_user_id', $this->salesTeam->currentSalesQuery($user)->pluck('users.id'));
        }

        return $query->whereIn('branch_id', $this->workspace->accessibleBranchIds($user));
    }

    private function historicalAgendas(User $user, CarbonImmutable $date): Collection
    {
        $branchIds = $user->isSales() ? [(int) $user->branch_id] : $this->workspace->accessibleBranchIds($user);

        $query = ContentItem::query()->where('item_type', 'agenda')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->whereNull('daily_plan_id')
            ->whereIn('branch_id', $branchIds)
            ->whereDate('scheduled_date', $date->toDateString())
            ->with(['owner:id,name', 'branch:id,name'])
            ->when($user->isSales(), fn ($query) => $query->where('owner_user_id', $user->id))
            ->when($user->hasPrimaryRole('sales_coordinator'), fn ($query) => $query->whereIn('owner_user_id', $this->salesTeam->currentSalesQuery($user)->pluck('users.id')))
            ->orderBy('start_time')->orderBy('id');

        return $query->get();
    }
}
