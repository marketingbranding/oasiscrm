<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\CompleteSalesDailyPlanItemRequest;
use App\Http\Requests\Crm\RescheduleSalesDailyPlanItemRequest;
use App\Http\Requests\Crm\ReviseSalesDailyPlanRequest;
use App\Http\Requests\Crm\StoreSalesDailyPlanRequest;
use App\Http\Requests\Crm\SubmitSalesDailyPlanRequest;
use App\Models\SalesDailyPlan;
use App\Models\SalesDailyPlanItem;
use App\Services\SalesDailyPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class SalesDailyPlanController extends Controller
{
    public function __construct(private readonly SalesDailyPlanService $plans) {}

    public function store(StoreSalesDailyPlanRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $date = CarbonImmutable::createFromFormat('Y-m-d', $data['work_date'], config('app.timezone'));
        $this->plans->saveDraft($request->user(), $date, $data['activities']);

        return to_route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $data['work_date']])->with('success', 'Draft Daily Plan berhasil disimpan.');
    }

    public function submit(SubmitSalesDailyPlanRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $date = CarbonImmutable::createFromFormat('Y-m-d', $data['work_date'], config('app.timezone'));
        $this->plans->submit($request->user(), $date);

        return to_route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $data['work_date']])->with('success', 'Daily Plan berhasil disubmit dan dikunci.');
    }

    public function reschedule(RescheduleSalesDailyPlanItemRequest $request, SalesDailyPlanItem $item): RedirectResponse
    {
        $result = $this->plans->rescheduleItem($request->user(), $item, $request->validated('reason'));
        $sourceDate = $result['source']->plan->work_date->toDateString();
        $targetDate = $result['target']->plan->work_date->locale('id')->translatedFormat('l, d F Y');

        return to_route('sales-agendas.index', ['tab' => 'agenda', 'work_date' => $sourceDate])
            ->with('success', "Agenda berhasil dijadwalkan ulang ke {$targetDate}.");
    }

    public function complete(CompleteSalesDailyPlanItemRequest $request, SalesDailyPlanItem $item): RedirectResponse
    {
        $this->plans->completeItem($request->user(), $item, $request->validated('activity_result'));

        return back()->with('success', 'Hasil aktivitas berhasil disimpan.');
    }

    public function revise(ReviseSalesDailyPlanRequest $request, SalesDailyPlan $plan): RedirectResponse
    {
        $this->plans->revise($request->user(), $plan, $request->validated('activities'));

        return back()->with('success', 'Planning anggota Team berhasil direvisi dan Sales akan menerima pemberitahuan.');
    }
}
