<?php

namespace App\Services;

use App\Enums\SalesLeadStatus;
use App\Models\SalesLead;
use App\Models\SalesLeadStatusHistory;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SalesLeadLifecycleService
{
    /** @return list<string> */
    public function allowedManualStatuses(): array
    {
        return array_map(fn (SalesLeadStatus $status) => $status->value, SalesLeadStatus::MANUAL);
    }

    /** @param iterable<string|SalesLeadStatus> $statuses */
    public function resolvePrimaryStatus(iterable $statuses): SalesLeadStatus
    {
        $resolved = SalesLeadStatus::NoResponse;

        foreach ($statuses as $status) {
            $candidate = SalesLeadStatus::fromInput($status);
            if ($candidate === SalesLeadStatus::Freelance) {
                continue;
            }

            if (($candidate->precedence() ?? -1) > ($resolved->precedence() ?? -1)) {
                $resolved = $candidate;
            }
        }

        return $resolved;
    }

    public function assertTransitionAllowed(
        SalesLead $lead,
        string|SalesLeadStatus $status,
        string $source = 'manual',
    ): void {
        $target = SalesLeadStatus::fromInput($status);

        if ($source === 'manual' && ! $target->isManual()) {
            throw new \DomainException('Status tersebut tidak dapat diubah secara manual.');
        }

        $current = $lead->current_status instanceof SalesLeadStatus
            ? $lead->current_status
            : SalesLeadStatus::fromInput($lead->current_status ?? SalesLeadStatus::NoResponse->value);

        if ($source === 'manual' && ($target->precedence() ?? -1) < ($current->precedence() ?? -1)) {
            throw new \DomainException('Status lead tidak dapat diturunkan secara manual.');
        }
    }

    public function recordStatusHistory(
        SalesLead $lead,
        string|SalesLeadStatus $status,
        string $source,
        ?string $sourceId = null,
        ?User $actor = null,
        ?CarbonInterface $changedAt = null,
        array $metadata = [],
        ?string $operationUuid = null,
    ): SalesLeadStatusHistory {
        $status = SalesLeadStatus::fromInput($status);
        $safeMetadata = array_intersect_key($metadata, array_flip([
            'reason', 'previous_status', 'remote_status', 'sheet_name', 'remote_row_number',
            'reconciliation_item_id', 'legacy_field',
        ]));

        $identity = $operationUuid !== null
            ? ['branch_id' => $lead->branch_id, 'operation_uuid' => $operationUuid]
            : [
                'sales_lead_id' => $lead->id,
                'source' => $source,
                'source_id' => $sourceId,
                'status' => $status->value,
            ];

        $existing = SalesLeadStatusHistory::query()->where($identity)->first();
        if ($existing !== null) {
            if ($existing->sales_lead_id !== $lead->id) {
                throw new \DomainException('Identitas operasi sudah digunakan oleh lead lain.');
            }

            return $existing;
        }

        return SalesLeadStatusHistory::query()->create($identity + [
            'sales_lead_id' => $lead->id,
            'branch_id' => $lead->branch_id,
            'actor_id' => $actor?->id,
            'status' => $status->value,
            'source' => $source,
            'source_id' => $sourceId,
            'operation_uuid' => $operationUuid,
            'changed_at' => $changedAt ?? now(),
            'metadata' => $safeMetadata ?: null,
        ]);
    }

    public function setManualStatus(
        SalesLead $lead,
        string|SalesLeadStatus $status,
        User $actor,
        ?CarbonInterface $changedAt = null,
        ?string $operationUuid = null,
    ): SalesLead {
        return DB::transaction(function () use ($lead, $status, $actor, $changedAt, $operationUuid): SalesLead {
            $locked = SalesLead::query()->lockForUpdate()->findOrFail($lead->id);
            $target = SalesLeadStatus::fromInput($status);
            $this->assertTransitionAllowed($locked, $target);
            $changedAt ??= now();

            if ($locked->current_status !== $target) {
                $previousStatus = $locked->current_status->value;
                $locked->update([
                    'current_status' => $target,
                    'current_status_changed_at' => $changedAt,
                    'current_status_source' => 'manual',
                    'current_status_source_id' => (string) $actor->id,
                    'updated_by' => $actor->id,
                ]);

                $this->recordStatusHistory(
                    $locked,
                    $target,
                    'manual',
                    (string) $actor->id,
                    $actor,
                    $changedAt,
                    ['previous_status' => $previousStatus],
                    $operationUuid,
                );
            }

            return $locked->fresh();
        });
    }
}
