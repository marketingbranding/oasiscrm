<?php

namespace App\Services;

use App\Models\ConsumerApplication;
use App\Models\ConsumerLeadLinkDecision;
use App\Models\ConsumerNup;
use Illuminate\Support\Facades\DB;

final class ConsumerLeadLinkCarryOverService
{
    public function carryApplication(ConsumerApplication $application): string
    {
        return DB::transaction(function () use ($application): string {
            $locked = ConsumerApplication::query()->lockForUpdate()->findOrFail($application->id);
            $sourceKeys = ['nup:'.$locked->source_nup_id];
            $legacyKey = $this->legacyKey($locked);
            if ($legacyKey !== null) {
                $sourceKeys[] = 'sheet:'.$locked->branch_id.':'.$legacyKey;
            }

            $source = ConsumerLeadLinkDecision::query()->whereIn('subject_key', $sourceKeys)->where('is_active', true)->lockForUpdate()->first();
            if ($source === null) {
                return 'not_found';
            }
            $current = ConsumerLeadLinkDecision::query()->where('subject_key', 'application:'.$locked->id)->where('is_active', true)->lockForUpdate()->first();
            if ($current !== null && $current->status !== $source->status) {
                return 'conflict';
            }
            if ($current !== null) {
                return 'already_moved';
            }

            $source->update(['is_active' => false]);
            ConsumerLeadLinkDecision::query()->create([
                'consumer_application_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'subject_type' => ConsumerLeadLinkDecision::SUBJECT_APPLICATION,
                'subject_key' => 'application:'.$locked->id,
                'status' => $source->status,
                'sales_lead_id' => $source->sales_lead_id,
                'decision_source' => 'carry_over',
                'suggestions' => $source->suggestions,
                'reason' => $source->reason,
                'decided_by' => $source->decided_by,
                'decided_at' => $source->decided_at,
                'is_active' => true,
            ]);

            return 'moved';
        });
    }

    public function carryNupConversion(ConsumerNup $nup, ConsumerApplication $application): string
    {
        return $this->carryApplication($application);
    }

    private function legacyKey(ConsumerApplication $application): ?string
    {
        $identity = $application->legacyIdentities()->value('legacy_id');

        return $identity === null ? null : mb_strtolower(trim((string) $identity));
    }
}
