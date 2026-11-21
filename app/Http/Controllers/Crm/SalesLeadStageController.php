<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\UpdateSalesLeadStageRequest;
use App\Models\SalesLead;
use App\Services\CollaborationNotificationService;
use App\Services\OptimisticLockService;
use App\Services\PresenceService;
use App\Services\SalesLeadService;
use App\Services\SalesLeadStageV3Service;
use App\Support\FeatureFlag;
use Symfony\Component\HttpFoundation\Response;

class SalesLeadStageController extends Controller
{
    public function __construct(
        private readonly SalesLeadService $leads,
        private readonly OptimisticLockService $optimisticLock,
        private readonly CollaborationNotificationService $notifications,
        private readonly PresenceService $presence,
        private readonly SalesLeadStageV3Service $stageV3,
    ) {}

    public function update(UpdateSalesLeadStageRequest $request, SalesLead $salesLead)
    {
        $data = $request->validated();
        $result = $this->optimisticLock->execute($request, $salesLead, $data['expected_updated_at'], function (SalesLead $current) use ($request, $data) {
            if (FeatureFlag::enabled('lead_stages_v3')) {
                $this->authorize('updateStageV3', $current);

                return $data['action'] === 'reverse'
                    ? $this->stageV3->correctStage($current, $data['stage'], $data['reason'], $request->user(), $data['operation_uuid'] ?? null)
                    : $this->stageV3->setStage($current, $data['stage'], $request->user(), $data['operation_uuid'] ?? null);
            }

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
            'message' => $data['action'] === 'reverse' ? 'Tahap lead berhasil dibatalkan.' : 'Tahap lead berhasil diperbarui.',
            'current_stage' => FeatureFlag::enabled('lead_stages_v3') ? $result->resolveStageV3() : $result->currentStage(),
            'current_stage_label' => FeatureFlag::enabled('lead_stages_v3') ? $result->stageV3Label() : $result->currentStageLabel(),
            'lead_stage' => $result->lead_stage,
            'updated_at' => $this->optimisticLock->token($result),
            'stages' => collect(FeatureFlag::enabled('lead_stages_v3') ? SalesLead::STAGE_V3_ORDER : SalesLead::STAGE_ORDER)->mapWithKeys(fn (string $stage) => [
                $stage => FeatureFlag::enabled('lead_stages_v3') ? ($result->lead_stage === $stage ? $result->lead_stage_changed_at?->toIso8601String() : null) : $result->{$stage}?->toIso8601String(),
            ]),
        ]);
    }
}
