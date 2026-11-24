<?php

namespace App\Services;

use App\Data\ConsumerLeadLinkSubject;
use App\Models\ActivityLog;
use App\Models\ConsumerApplication;
use App\Models\ConsumerLeadLinkDecision;
use App\Models\ConsumerNup;
use Illuminate\Support\Collection;
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

            $sources = ConsumerLeadLinkDecision::query()
                ->whereNotNull('subject_key')
                ->whereIn('subject_key', $sourceKeys)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get();
            $current = ConsumerLeadLinkDecision::query()->where('subject_key', 'application:'.$locked->id)->where('is_active', true)->lockForUpdate()->first();
            if ($sources->isEmpty()) {
                return $current === null ? 'not_found' : 'already_moved';
            }
            $baseline = $sources->first();
            $sameSourceDecision = fn (ConsumerLeadLinkDecision $decision): bool => $decision->status === $baseline->status
                && (int) ($decision->sales_lead_id ?? 0) === (int) ($baseline->sales_lead_id ?? 0);
            $sourcesAgree = $sources->every($sameSourceDecision);

            if (! $sourcesAgree) {
                $this->recordSourceConflicts($sources, $locked, $current);

                return 'conflict';
            }
            if ($current !== null && ($current->status !== $baseline->status || (int) ($current->sales_lead_id ?? 0) !== (int) ($baseline->sales_lead_id ?? 0))) {
                $this->recordSourceConflicts($sources, $locked, $current);

                return 'conflict';
            }
            if ($current !== null) {
                $sources->each(fn (ConsumerLeadLinkDecision $source): int => $source->update(['is_active' => false]));

                return 'already_moved';
            }

            $sources->each(fn (ConsumerLeadLinkDecision $source): int => $source->update(['is_active' => false]));
            $target = ConsumerLeadLinkDecision::query()->create([
                'consumer_application_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'subject_type' => ConsumerLeadLinkDecision::SUBJECT_APPLICATION,
                'subject_key' => 'application:'.$locked->id,
                'status' => $baseline->status,
                'sales_lead_id' => $baseline->sales_lead_id,
                'decision_source' => 'carry_over',
                'suggestions' => $baseline->suggestions,
                'reason' => $baseline->reason,
                'decided_by' => $baseline->decided_by,
                'decided_at' => $baseline->decided_at,
                'is_active' => true,
            ]);

            ActivityLog::create(['causer_id' => null, 'subject_type' => ConsumerApplication::class, 'subject_id' => $locked->id, 'event' => 'consumer_lead_link_carry_over', 'description' => 'Keputusan penautan Lead dibawa ke ConsumerApplication.', 'properties' => ['source_decision_ids' => $sources->pluck('id')->values()->all(), 'target_decision_id' => $target->id, 'actor' => 'system']]);

            return 'moved';
        });
    }

    public function carryNupConversion(ConsumerNup $nup, ConsumerApplication $application): string
    {
        return $this->carryApplication($application);
    }

    /** @param Collection<int, ConsumerLeadLinkDecision> $sources */
    private function recordSourceConflicts(Collection $sources, ConsumerApplication $application, ?ConsumerLeadLinkDecision $target): void
    {
        $created = false;
        foreach ($sources as $source) {
            if ($target !== null && $source->status === $target->status && (int) ($source->sales_lead_id ?? 0) === (int) ($target->sales_lead_id ?? 0)) {
                continue;
            }
            $conflictType = $target !== null && $source->status !== $target->status ? 'status' : 'lead';
            $exists = DB::table('consumer_lead_link_conflicts')->where('source_decision_id', $source->id)->where('target_decision_id', $target?->id)->exists();
            if (! $exists) {
                DB::table('consumer_lead_link_conflicts')->insert([
                    'source_decision_id' => $source->id,
                    'target_decision_id' => $target?->id,
                    'consumer_application_id' => $application->id,
                    'conflict_type' => $conflictType,
                    'details' => json_encode(['source_status' => $source->status, 'target_status' => $target?->status, 'source_lead_id' => $source->sales_lead_id, 'target_lead_id' => $target?->sales_lead_id]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created = true;
            }
        }
        if (! $created) {
            return;
        }
        ActivityLog::create(['causer_id' => null, 'subject_type' => ConsumerApplication::class, 'subject_id' => $application->id, 'event' => 'consumer_lead_link_carry_over_conflict', 'description' => 'Konflik carry-over penautan Lead perlu ditinjau.', 'properties' => ['source_decision_ids' => $sources->pluck('id')->values()->all(), 'target_decision_id' => $target?->id]]);
    }

    private function legacyKey(ConsumerApplication $application): ?string
    {
        $identity = $application->legacyIdentities()->whereNotNull('id_kons')->orderBy('id')->value('id_kons');

        return ConsumerLeadLinkSubject::normalizeLegacyConsumerKey($identity);
    }
}
