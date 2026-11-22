<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\SalesLead;
use App\Models\SalesLeadTransfer;
use App\Models\SalesLeadUnownedQueue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesLeadTransferService
{
    public function __construct(
        private readonly CoordinatorLeadTeamService $teams,
        private readonly AppSettingResolver $settings,
        private readonly CollaborationNotificationService $notifications,
    ) {}

    /**
     * @param  list<int>  $leadIds
     * @return list<SalesLeadTransfer>
     */
    public function transfer(User $actor, array $leadIds, User $target, string $reason, ?string $notes = null, ?int $queueId = null): array
    {
        $allowedReasons = $this->settings->value('sales_lead.transfer_reasons');
        if (! is_array($allowedReasons) || ! in_array($reason, $allowedReasons, true)) {
            throw ValidationException::withMessages(['reason' => 'Alasan alih Lead tidak tersedia di Pengaturan.']);
        }
        if (! $actor->isAccountActive() || ! $actor->is_active) {
            throw ValidationException::withMessages(['actor' => 'Akun pelaku tidak aktif.']);
        }

        return DB::transaction(function () use ($actor, $leadIds, $target, $reason, $notes, $queueId): array {
            $queue = $queueId === null ? null : SalesLeadUnownedQueue::query()->whereKey($queueId)->where('status', 'open')->lockForUpdate()->firstOrFail();
            $queueLeadIds = $queue === null ? [] : [$queue->sales_lead_id];
            $leadIds = array_values(array_unique([...$leadIds, ...$queueLeadIds]));
            if ($queue !== null && ! $actor->isSuperadmin() && (int) $queue->queue_coordinator_user_id !== (int) $actor->id) {
                throw ValidationException::withMessages(['queue_id' => 'Antrean bukan milik Koordinator ini.']);
            }
            if ($queue !== null && $actor->isSuperadmin() && $queue->queue_coordinator_user_id !== null) {
                throw ValidationException::withMessages(['queue_id' => 'Superadmin hanya dapat mengambil antrean tanpa Koordinator aktif.']);
            }
            $targetAllowed = $queue !== null
                ? ($actor->isSuperadmin() ? $target->hasPrimaryRole('sales') && $target->isAccountActive() && $target->is_active && (int) $target->branch_id === (int) $queue->lead()->value('branch_id') : $this->teams->contains($actor, $target))
                : $this->teams->isCoordinator($actor) && $this->teams->contains($actor, $target);
            if (! $targetAllowed) {
                throw ValidationException::withMessages(['target_user_id' => 'Sales tujuan tidak valid untuk konteks alih.']);
            }

            $leads = SalesLead::query()->whereIn('id', $leadIds)->lockForUpdate()->get()->keyBy('id');
            $transfers = [];
            foreach ($leadIds as $leadId) {
                $lead = $leads->get($leadId);
                $leadQueue = $queue?->sales_lead_id === $leadId ? $queue : SalesLeadUnownedQueue::query()->where('sales_lead_id', $leadId)->where('status', 'open')->lockForUpdate()->first();
                $teamOwned = $this->teams->isCoordinator($actor) && $this->teams->contains($actor, (int) $lead?->sales_user_id);
                if ($lead === null || (! $teamOwned && $leadQueue === null)) {
                    throw ValidationException::withMessages(['lead_ids' => "Lead {$leadId} bukan milik tim aktif atau antrean yang dapat diambil."]);
                }
                if (in_array($lead->resolveStageV3(), ['gugur', 'utj'], true)) {
                    throw ValidationException::withMessages(['lead_ids' => "Lead {$leadId} tidak dapat dialihkan karena sudah {$lead->stageV3Label()}."]);
                }
                if ((int) $lead->sales_user_id === (int) $target->id) {
                    throw ValidationException::withMessages(['lead_ids' => "Lead {$leadId} sudah dimiliki Sales tujuan."]);
                }
                $from = (int) $lead->sales_user_id;
                $lead->update(['sales_user_id' => $target->id]);
                $transfers[] = SalesLeadTransfer::create(['sales_lead_id' => $lead->id, 'from_sales_user_id' => $from, 'to_sales_user_id' => $target->id, 'actor_user_id' => $actor->id, 'reason' => $reason, 'notes' => $notes]);
                ActivityLog::create(['causer_id' => $actor->id, 'subject_type' => SalesLead::class, 'subject_id' => $lead->id, 'event' => 'sales_lead_transferred', 'description' => "Lead {$lead->id} dialihkan kepada {$target->name}.", 'properties' => ['from_sales_user_id' => $from, 'to_sales_user_id' => $target->id, 'reason' => $reason]]);
                $this->notifications->create($target, 'lead_transferred_to_me', 'Lead dialihkan kepada saya', "Lead {$lead->id} dialihkan kepada Anda.", null, $lead);
                if ($leadQueue !== null) {
                    $leadQueue->update(['status' => 'closed', 'resolved_by_user_id' => $actor->id, 'resolved_at' => now(), 'closed_by' => $actor->id, 'closed_at' => now()]);
                }
            }

            return $transfers;
        });
    }
}
