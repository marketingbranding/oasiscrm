<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\TransferSalesLeadRequest;
use App\Models\SalesLeadUnownedQueue;
use App\Models\User;
use App\Services\SalesLeadTransferService;
use Illuminate\Http\JsonResponse;

class SalesLeadTransferController extends Controller
{
    public function __construct(private readonly SalesLeadTransferService $transfers) {}

    public function store(TransferSalesLeadRequest $request): JsonResponse
    {
        $target = User::query()->findOrFail($request->integer('target_user_id'));
        $transfers = $this->transfers->transfer(
            $request->user(),
            array_map('intval', $request->input('lead_ids')),
            $target,
            $request->string('reason')->toString(),
            $request->input('notes'),
        );

        return response()->json(['transferred' => count($transfers)]);
    }

    public function queue(): JsonResponse
    {
        $user = request()->user();
        abort_unless($user->hasPermission('sales_pocketbook.manage_team') || $user->isSuperadmin(), 403);

        $query = SalesLeadUnownedQueue::query()->with(['lead', 'formerSales', 'coordinator'])->where('status', 'open');
        if (! $user->isSuperadmin()) {
            $query->where('queue_coordinator_user_id', $user->id);
        }

        return response()->json(['queue' => $query->latest()->get()]);
    }
}
