<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\CompleteSalesPocketbookOnboardingRequest;
use App\Services\SalesPocketbookOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesPocketbookOnboardingController extends Controller
{
    public function __construct(private readonly SalesPocketbookOnboardingService $onboarding) {}

    public function show(Request $request): View|RedirectResponse
    {
        $this->authorizeAccess($request);

        return redirect()->route('sales-agendas.index', ['tour' => 1]);
    }

    public function skip(Request $request): RedirectResponse
    {
        $this->authorizeAccess($request);
        $this->onboarding->skip($request->user());

        return redirect()->route('sales-agendas.index');
    }

    public function complete(CompleteSalesPocketbookOnboardingRequest $request): RedirectResponse
    {
        $this->authorizeAccess($request);
        $this->onboarding->complete($request->user(), (int) $request->validated('current_step'));

        return redirect()->route('sales-agendas.index');
    }

    public function replay(Request $request): RedirectResponse
    {
        $this->authorizeAccess($request);
        $this->onboarding->replay($request->user());

        return redirect()->route('sales-agendas.index', ['tour' => 1]);
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($this->onboarding->canAccess($request->user()), 403);
    }
}
