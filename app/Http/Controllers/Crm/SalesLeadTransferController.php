<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\TransferSalesLeadRequest;
use App\Models\SalesLeadUnownedQueue;
use App\Models\User;
use App\Services\AppSettingResolver;
use App\Services\CoordinatorLeadTeamService;
use App\Services\SalesLeadTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesLeadTransferController extends Controller
{
    public function __construct(
        private readonly SalesLeadTransferService $transfers,
        private readonly CoordinatorLeadTeamService $teams,
        private readonly AppSettingResolver $settings,
    ) {}

    public function store(TransferSalesLeadRequest $request): JsonResponse|RedirectResponse
    {
        $target = User::query()->findOrFail($request->integer('target_user_id'));
        $transfers = $this->transfers->transfer(
            $request->user(),
            array_map('intval', $request->input('lead_ids')),
            $target,
            $request->string('reason')->toString(),
            $request->input('notes'),
            $request->integer('queue_id') ?: null,
        );

        return $request->expectsJson()
            ? response()->json(['transferred' => count($transfers)])
            : redirect()->back()->with('success', count($transfers).' Lead berhasil dialihkan.');
    }

    public function queue(Request $request): View|JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperadmin() || ($this->teams->isCoordinator($user) && $user->hasPermission('sales_pocketbook.manage_team')), 403);

        $query = SalesLeadUnownedQueue::query()->with(['lead.sales', 'lead.project', 'formerSales', 'coordinator'])->where('status', 'open');
        if (! $user->isSuperadmin()) {
            $query->where(function ($scope) use ($user): void {
                $scope->where('queue_coordinator_user_id', $user->id)->orWhereNull('queue_coordinator_user_id');
            });
        }
        $queue = $query->latest()->get();
        $targets = $user->isSuperadmin()
            ? User::query()->whereHas('role', fn ($role) => $role->where('slug', 'sales'))->where('account_status', 'active')->where('is_active', true)->orderBy('name')->get()
            : $this->teams->currentSales($user);
        $data = [
            'queue' => $queue,
            'queueTransferTargets' => $targets,
            'transferReasons' => $this->settings->value('sales_lead.transfer_reasons') ?? [],
        ];

        return $request->expectsJson()
            ? response()->json(['queue' => $queue])
            : view('crm.sales-pocketbook.unowned-queue', $data);
    }
}
