<?php

namespace App\Services;

use App\Data\ConsumerLeadLinkSubject;
use App\Models\ActivityLog;
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
            $sourceKeys = $locked->source_nup_id === null ? [] : ['nup:'.$locked->source_nup_id];
            $legacyKey = $this->legacyKey($locked);
            if ($legacyKey !== null) {
                $sourceKeys[] = 'sheet:'.$locked->branch_id.':'.$legacyKey;
            }

            $source = ConsumerLeadLinkDecision::query()->whereIn('subject_key', $sourceKeys)->where('is_active', true)->lockForUpdate()->first();
            if ($source === null) {
                return 'not_found';
            }
            $current = ConsumerLeadLinkDecision::query()->where('subject_key', 'application:'.$locked->id)->where('is_active', true)->lockForUpdate()->first();
            if ($current !== null && ($current->status !== $source->status || (int) ($current->sales_lead_id ?? 0) !== (int) ($source->sales_lead_id ?? 0))) {
                DB::table('consumer_lead_link_conflicts')->insert([
                    'source_decision_id' => $source->id,
                    'target_decision_id' => $current->id,
                    'consumer_application_id' => $locked->id,
                    'conflict_type' => $current->status !== $source->status ? 'status' : 'lead',
                    'details' => json_encode(['source_status' => $source->status, 'target_status' => $current->status, 'source_lead_id' => $source->sales_lead_id, 'target_lead_id' => $current->sales_lead_id]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                ActivityLog::create(['causer_id' => null, 'subject_type' => ConsumerApplication::class, 'subject_id' => $locked->id, 'event' => 'consumer_lead_link_carry_over_conflict', 'description' => 'Konflik carry-over penautan Lead perlu ditinjau.', 'properties' => ['source_decision_id' => $source->id, 'target_decision_id' => $current->id]]);

                return 'conflict';
            }
            if ($current !== null) {
                $source->update(['is_active' => false]);

                return 'already_moved';
            }

            $source->update(['is_active' => false]);
            $target = ConsumerLeadLinkDecision::query()->create([
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

            ActivityLog::create(['causer_id' => null, 'subject_type' => ConsumerApplication::class, 'subject_id' => $locked->id, 'event' => 'consumer_lead_link_carry_over', 'description' => 'Keputusan penautan Lead dibawa ke ConsumerApplication.', 'properties' => ['source_decision_id' => $source->id, 'target_decision_id' => $target->id, 'actor' => 'system']]);

            return 'moved';
        });
    }

    public function carryNupConversion(ConsumerNup $nup, ConsumerApplication $application): string
    {
        return $this->carryApplication($application);
    }

    private function legacyKey(ConsumerApplication $application): ?string
    {
        $identity = $application->legacyIdentities()->whereNotNull('id_kons')->orderBy('id')->value('id_kons');

        return ConsumerLeadLinkSubject::normalizeLegacyConsumerKey($identity);
    }
}
