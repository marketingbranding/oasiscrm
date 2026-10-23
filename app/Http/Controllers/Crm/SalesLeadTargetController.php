<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreSalesLeadTargetRequest;
use App\Models\SalesLeadTarget;
use App\Models\User;
use App\Services\SalesLeadTargetService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesLeadTargetController extends Controller
{
    public function __construct(private readonly SalesLeadTargetService $targets) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->isSuperadmin(), 403);
        $monthValue = $request->query('month', now(config('app.timezone'))->format('Y-m'));
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = CarbonImmutable::createFromFormat('Y-m-d', $monthValue.'-01', config('app.timezone'));
        $salesUsers = User::query()
            ->with('role:id,slug')
            ->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->where('slug', 'sales'))
            ->orderBy('name')
            ->get(['id', 'name', 'branch_id']);
        $targets = SalesLeadTarget::query()
            ->whereDate('target_month', $month->toDateString())
            ->whereIn('sales_user_id', $salesUsers->pluck('id'))
            ->get()
            ->keyBy('sales_user_id');
        $summaries = $salesUsers->mapWithKeys(fn (User $sales) => [$sales->id => $this->targets->summary($sales, $month)]);

        return view('crm.sales-lead-targets.index', compact('month', 'salesUsers', 'targets', 'summaries'));
    }

    public function store(StoreSalesLeadTargetRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $sales = User::query()->findOrFail($data['sales_user_id']);
        $month = CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01', config('app.timezone'));
        $this->targets->upsert($sales, $month, (int) $data['target_leads'], $request->user());

        return to_route('sales-lead-targets.index', ['month' => $data['month']])->with('success', 'Target Lead berhasil disimpan.');
    }
}
