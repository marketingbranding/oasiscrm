<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\CompleteSalesLeadRequest;
use App\Models\SalesLead;
use App\Services\OptimisticLockService;
use App\Services\SalesLeadCompletionService;
use Symfony\Component\HttpFoundation\Response;

class SalesLeadCompletionController extends Controller
{
    public function __construct(
        private readonly OptimisticLockService $locks,
        private readonly SalesLeadCompletionService $completion,
    ) {}

    public function __invoke(CompleteSalesLeadRequest $request, SalesLead $salesLead): Response
    {
        $result = $this->locks->execute($request, $salesLead, $request->validated('expected_updated_at'), function (SalesLead $current) use ($request): SalesLead {
            return $this->completion->complete($current, $request->user());
        });

        if ($result instanceof Response) {
            return $result;
        }

        return redirect()->route('sales-agendas.index', [
            'tab' => 'leads',
            'lead_status' => 'open',
        ])->with('success', 'Lead ditandai selesai.');
    }
}
