<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\SalesLead;
use App\Models\SalesLeadTransfer;
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
    public function transfer(User $actor, array $leadIds, User $target, string $reason, ?string $notes = null): array
    {
        $allowedReasons = $this->settings->value('sales_lead.transfer_reasons');
        if (! is_array($allowedReasons) || ! in_array($reason, $allowedReasons, true)) {
            throw ValidationException::withMessages(['reason' => 'Alasan alih Lead tidak tersedia di Pengaturan.']);
        }

        if (! $this->teams->isCoordinator($actor) || ! $actor->isAccountActive() || ! $actor->is_active || ! $this->teams->contains($actor, $target)) {
            throw ValidationException::withMessages(['target_user_id' => 'Sales tujuan harus berada dalam tim aktif Koordinator.']);
        }

        return DB::transaction(function () use ($actor, $leadIds, $target, $reason, $notes): array {
            $leads = SalesLead::query()->whereIn('id', $leadIds)->lockForUpdate()->get()->keyBy('id');
            $transfers = [];

            foreach ($leadIds as $leadId) {
                $lead = $leads->get($leadId);
                if ($lead === null || ! $this->teams->contains($actor, (int) $lead->sales_user_id)) {
                    throw ValidationException::withMessages(['lead_ids' => "Lead {$leadId} bukan milik tim aktif Koordinator."]);
                }
                if ($lead->resolveStageV3() === 'gugur' || $lead->resolveStageV3() === 'utj') {
                    throw ValidationException::withMessages(['lead_ids' => "Lead {$leadId} tidak dapat dialihkan karena sudah {$lead->stageV3Label()}."]);
                }
                if ((int) $lead->sales_user_id === (int) $target->id) {
                    throw ValidationException::withMessages(['lead_ids' => "Lead {$leadId} sudah dimiliki Sales tujuan."]);
                }

                $from = (int) $lead->sales_user_id;
                $lead->update(['sales_user_id' => $target->id]);
                $transfers[] = SalesLeadTransfer::create([
                    'sales_lead_id' => $lead->id,
                    'from_sales_user_id' => $from,
                    'to_sales_user_id' => $target->id,
                    'actor_user_id' => $actor->id,
                    'reason' => $reason,
                    'notes' => $notes,
                ]);
                ActivityLog::create([
                    'causer_id' => $actor->id,
                    'subject_type' => SalesLead::class,
                    'subject_id' => $lead->id,
                    'event' => 'sales_lead_transferred',
                    'description' => "Lead {$lead->id} dialihkan kepada {$target->name}.",
                    'properties' => ['from_sales_user_id' => $from, 'to_sales_user_id' => $target->id, 'reason' => $reason],
                ]);
                $this->notifications->create($target, 'lead_transferred_to_me', 'Lead dialihkan kepada saya', "Lead {$lead->id} dialihkan kepada Anda.", null, $lead);
            }

            return $transfers;
        });
    }
}
