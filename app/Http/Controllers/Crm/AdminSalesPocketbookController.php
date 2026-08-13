<?php

namespace App\Http\Controllers\Crm;

use App\Enums\SalesLeadStatus;
use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Services\AdminBranchSalesMonitoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminSalesPocketbookController extends Controller
{
    public function __construct(private readonly AdminBranchSalesMonitoringService $monitoring) {}

    public function index(Request $request): View|RedirectResponse
    {
        abort_unless($request->user()->hasPrimaryRole('admin'), 403);
        abort_unless($request->user()->hasScopedPermission('sales_pocketbook'), 403);

        if ($request->query('tab') === 'report') {
            return redirect()->route('sales-fee-reports.index', array_filter([
                'period' => $request->query('period'),
                'date_from' => $request->query('date_from'),
                'date_to' => $request->query('date_to'),
            ], fn ($value) => filled($value)));
        }

        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['leads', 'agenda'])],
            'period' => ['nullable', 'date_format:Y-m'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'project_id' => ['nullable', 'integer', 'min:1'],
            'coordinator_id' => ['nullable', 'integer', 'min:1'],
            'sales_user_id' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(SalesLeadStatus::class)],
            'agenda_category' => ['nullable', Rule::in(ContentItem::SALES_ACTIVITY_CATEGORIES)],
            'agenda_status' => ['nullable', Rule::in(ContentItem::STATUSES['agenda'])],
        ]);

        return view('crm.sales-pocketbook.admin-monitoring', $this->monitoring->resolve($request->user(), $filters));
    }
}
