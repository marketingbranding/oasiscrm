<?php

namespace App\Services;

use App\Data\ConsumerComparisonRecord;
use App\Data\ConsumerComparisonResult;
use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\LeadMaster;
use Illuminate\Support\Str;

final class ConsumerReadComparisonService
{
    private const FIELDS = ['name', 'phone', 'sales', 'kavling', 'status', 'stage', 'booking_date', 'akad_date', 'bank', 'bank_status'];

    public function __construct(
        private readonly LegacyConsumerReadService $legacy,
        private readonly LocalConsumerReadService $local,
    ) {}

    public function compare(Branch $branch, LeadMaster $project): ConsumerComparisonResult
    {
        $legacy = $this->legacy->records($branch, $project);
        $local = $this->local->records($branch, $project);
        $identityIndexes = array_fill_keys(['transaction', 'consumer', 'psjb', 'external', 'external_raw'], []);
        $duplicateKeys = [];
        $legacyIdentityCounts = [];
        foreach ($legacy as $record) {
            foreach ($this->identities($record, true) as $type => $key) {
                $legacyIdentityCounts[$type.':'.$key] = ($legacyIdentityCounts[$type.':'.$key] ?? 0) + 1;
            }
        }
        foreach ($legacyIdentityCounts as $key => $count) {
            if ($count > 1) {
                $duplicateKeys['legacy:'.$key] = true;
            }
        }
        foreach ($local as $record) {
            foreach ($this->identities($record, false) as $type => $key) {
                $identityIndexes[$type][$key][] = $record;
            }
        }
        foreach ($identityIndexes as $type => $index) {
            foreach ($index as $key => $records) {
                if (count($records) > 1) {
                    $duplicateKeys[$type.':'.$key] = true;
                }
            }
        }
        $rows = [];
        $usedLocal = [];
        $fieldMismatches = array_fill_keys(self::FIELDS, 0);
        $summary = array_fill_keys(['MATCHED', 'LEGACY_ONLY', 'LOCAL_ONLY', 'AMBIGUOUS', 'MISMATCH'], 0);
        $exact = 0;

        foreach ($legacy as $legacyRecord) {
            [$candidates, $matchKey] = $this->findCandidates($legacyRecord, $identityIndexes, $duplicateKeys);
            $resolutionSource = $matchKey;
            $legacyIdentities = $this->identities($legacyRecord, true);
            $legacyDuplicate = collect($legacyIdentities)->contains(fn (string $key, string $type): bool => isset($duplicateKeys['legacy:'.$type.':'.$key]));
            if (count($candidates) > 1 || $legacyDuplicate) {
                foreach ($candidates as $candidate) {
                    $usedLocal[$candidate->localApplicationId] = true;
                }
                $summary['AMBIGUOUS']++;
                $rows[] = $this->row('AMBIGUOUS', $legacyRecord, null, [], ['Multiple local applications share stable identity.']);

                continue;
            }
            if ($candidates === []) {
                $summary['LEGACY_ONLY']++;
                $rows[] = $this->row('LEGACY_ONLY', $legacyRecord, null, [], $legacyRecord->notes);

                continue;
            }

            $localRecord = $candidates[0];
            $usedLocal[$localRecord->localApplicationId] = true;
            $mismatches = $this->mismatches($legacyRecord, $localRecord);
            foreach ($mismatches as $field) {
                $fieldMismatches[$field]++;
            }
            $status = $mismatches === [] ? 'MATCHED' : 'MISMATCH';
            $summary[$status]++;
            if ($status === 'MATCHED') {
                $exact++;
            }
            $rows[] = $this->row($status, $legacyRecord, $localRecord, $mismatches, [...$legacyRecord->notes, ...$localRecord->notes], $resolutionSource);
        }

        foreach ($local as $localRecord) {
            if (! isset($usedLocal[$localRecord->localApplicationId]) && ! isset($duplicateKeys[$localRecord->legacyKey])) {
                $summary['LOCAL_ONLY']++;
                $rows[] = $this->row('LOCAL_ONLY', null, $localRecord, [], $localRecord->notes);
            }
        }

        $linked = $summary['MATCHED'] + $summary['MISMATCH'];

        return new ConsumerComparisonResult(
            rows: $rows,
            summary: [
                'total_legacy' => count($legacy), 'total_local' => count($local),
                'matched' => $summary['MATCHED'], 'exact_match' => $exact,
                'mismatch' => $summary['MISMATCH'], 'legacy_only' => $summary['LEGACY_ONLY'],
                'local_only' => $summary['LOCAL_ONLY'], 'ambiguous' => $summary['AMBIGUOUS'],
                'identity_issues' => $this->legacy->identityIssues($branch),
            ],
            fieldMismatches: $fieldMismatches,
            coverage: [
                'linked' => $linked,
                'link_coverage_percent' => count($legacy) ? round($linked / count($legacy) * 100, 2) : 0,
                'exact_match_percent' => $linked ? round($exact / $linked * 100, 2) : 0,
            ],
        );
    }

    private function findCandidates(ConsumerComparisonRecord $legacy, array $indexes, array $duplicates): array
    {
        foreach ($this->identities($legacy, true) as $type => $key) {
            $compound = $type.':'.$key;
            if (isset($duplicates[$compound])) {
                return [$indexes[$type][$key] ?? [], $type === 'external' ? 'EXISTING_PROVENANCE' : strtoupper($type)];
            }
            if (($indexes[$type][$key] ?? []) !== []) {
                return [$indexes[$type][$key], $type === 'external' || $type === 'external_raw' ? 'EXISTING_PROVENANCE' : strtoupper($type)];
            }
        }

        return [[], null];
    }

    private function identities(ConsumerComparisonRecord $record, bool $legacy): array
    {
        $identities = [];
        foreach (['transaction' => $record->transactionId, 'consumer' => $record->consumerId, 'psjb' => $record->psjbId] as $type => $value) {
            if (filled($value)) {
                $identities[$type] = Str::lower(trim((string) $value));
            }
        }
        if ($record->legacyKey !== '') {
            $identities['external'] = Str::lower($record->legacyKey);
        }
        foreach ($record->values['provenance'] ?? [] as $provenance) {
            if (filled($provenance['external_key'] ?? null)) {
                $external = Str::lower(trim((string) $provenance['external_key']));
                $identities['external'] = Str::startsWith($external, 'external:') ? $external : 'external:'.$external;
                $identities['external_raw'] = Str::startsWith($external, 'external:') ? Str::after($external, 'external:') : $external;
                break;
            }
        }

        return $identities;
    }

    private function mismatches(ConsumerComparisonRecord $legacy, ConsumerComparisonRecord $local): array
    {
        $pairs = [
            'name' => [$legacy->customerName, $local->customerName],
            'phone' => [$legacy->phone, $local->phone],
            'sales' => [$legacy->salesUserId ?: $legacy->salesLabel, $local->salesUserId ?: $local->salesLabel],
            'kavling' => [$legacy->kavlingId ?: $legacy->kavlingLabel, $local->kavlingId ?: $local->kavlingLabel],
            'status' => [ConsumerApplication::canonicalTransactionStatusDetailsFor($legacy->applicationStatus)['status'], ConsumerApplication::canonicalTransactionStatusDetailsFor($local->applicationStatus)['status']],
            'stage' => [$this->stage($legacy->currentStage), $this->stage($local->currentStage)],
            'booking_date' => [$legacy->bookingDate, $local->bookingDate],
            'akad_date' => [$legacy->akadDate, $local->akadDate],
            'bank' => [$legacy->bankName, $local->bankName],
            'bank_status' => [$legacy->bankStatus, $local->bankStatus],
        ];

        return collect($pairs)->filter(fn (array $values) => $this->value($values[0]) !== $this->value($values[1]))->keys()->all();
    }

    private function stage(?string $value): ?string
    {
        return $value === null ? null : app(KonsumenPipelineService::class)->canonicalStage($value) ?? Str::lower(trim($value));
    }

    private function value(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : Str::lower(trim((string) $value));
    }

    private function row(string $status, ?ConsumerComparisonRecord $legacy, ?ConsumerComparisonRecord $local, array $mismatches, array $notes, ?string $resolutionSource = null): array
    {
        $record = $local ?: $legacy;

        return [
            'status' => $status,
            'legacy_identifier' => $legacy?->legacyKey,
            'local_application_id' => $local?->localApplicationId,
            'customer_name' => $record?->customerName,
            'project' => $record?->values['project_name'] ?? null,
            'kavling' => $record?->kavlingLabel,
            'legacy_values' => $legacy?->toArray(),
            'local_values' => $local?->toArray(),
            'mismatch_fields' => $mismatches,
            'match_key' => $resolutionSource,
            'notes' => array_values(array_unique(array_filter([...$notes, $resolutionSource]))),
        ];
    }
}
