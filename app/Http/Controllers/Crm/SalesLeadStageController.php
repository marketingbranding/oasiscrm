<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\UpdateSalesLeadStageRequest;
use App\Models\SalesLead;
use App\Services\CollaborationNotificationService;
use App\Services\OptimisticLockService;
use App\Services\PresenceService;
use App\Services\SalesLeadService;
use Symfony\Component\HttpFoundation\Response;

class SalesLeadStageController extends Controller
{
    public function __construct(
        private readonly SalesLeadService $leads,
        private readonly OptimisticLockService $optimisticLock,
        private readonly CollaborationNotificationService $notifications,
        private readonly PresenceService $presence,
    ) {}

    public function update(UpdateSalesLeadStageRequest $request, SalesLead $salesLead)
    {
        $data = $request->validated();
        $result = $this->optimisticLock->execute($request, $salesLead, $data['expected_updated_at'], function (SalesLead $current) use ($request, $data) {
            if ($data['action'] === 'reverse') {
                $this->authorize('reverseStage', $current);

                return $this->leads->reverseStage($current, $data['stage'], $request->user());
            }

            $this->authorize('updateStage', $current);

            return $this->leads->setStage($current, $data['stage'], $data['timestamp'], $request->user());
        });
        if ($result instanceof Response) {
            return $result;
        }

        $this->presence->clearEditing($request->user(), $result, $request->input('presence_session_key'));
        $this->notifications->recordUpdated($result, $request->user(), route('sales-pocketbook.index'));

        return response()->json([
            'ok' => true,
            'current_stage' => $result->currentStage(),
            'current_stage_label' => $result->currentStageLabel(),
            'updated_at' => $this->optimisticLock->token($result),
            'stages' => collect(SalesLead::STAGE_ORDER)->mapWithKeys(fn (string $stage) => [
                $stage => $result->{$stage}?->toIso8601String(),
            ]),
        ]);
    }
}
