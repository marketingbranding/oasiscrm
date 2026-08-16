<?php

namespace App\Services;

use App\Data\ConsumerComparisonRecord;
use App\Data\ConsumerComparisonResult;
use App\Models\Branch;
use App\Models\LeadMaster;
use Illuminate\Support\Str;

final class ConsumerReadComparisonService
{
    private const FIELDS = ['name', 'phone', 'sales', 'kavling', 'stage', 'booking_date', 'akad_date', 'bank', 'bank_status'];

    public function __construct(
        private readonly LegacyConsumerReadService $legacy,
        private readonly LocalConsumerReadService $local,
    ) {}

    public function compare(Branch $branch, LeadMaster $project): ConsumerComparisonResult
    {
        $legacy = $this->legacy->records($branch, $project);
        $local = $this->local->records($branch, $project);
        $localByKey = [];
        $duplicateKeys = [];
        foreach ($local as $record) {
            if (isset($localByKey[$record->legacyKey])) {
                $duplicateKeys[$record->legacyKey] = true;
            }
            $localByKey[$record->legacyKey][] = $record;
        }

        $rows = [];
        $usedLocal = [];
        $fieldMismatches = array_fill_keys(self::FIELDS, 0);
        $summary = array_fill_keys(['MATCHED', 'LEGACY_ONLY', 'LOCAL_ONLY', 'AMBIGUOUS', 'MISMATCH'], 0);
        $exact = 0;

        foreach ($legacy as $legacyRecord) {
            $candidates = $localByKey[$legacyRecord->legacyKey] ?? [];
            if (count($candidates) > 1 || isset($duplicateKeys[$legacyRecord->legacyKey])) {
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
            $rows[] = $this->row($status, $legacyRecord, $localRecord, $mismatches, [...$legacyRecord->notes, ...$localRecord->notes]);
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
            ],
            fieldMismatches: $fieldMismatches,
            coverage: [
                'linked' => $linked,
                'link_coverage_percent' => count($legacy) ? round($linked / count($legacy) * 100, 2) : 0,
                'exact_match_percent' => $linked ? round($exact / $linked * 100, 2) : 0,
            ],
        );
    }

    private function mismatches(ConsumerComparisonRecord $legacy, ConsumerComparisonRecord $local): array
    {
        $pairs = [
            'name' => [$legacy->customerName, $local->customerName],
            'phone' => [$legacy->phone, $local->phone],
            'sales' => [$legacy->salesUserId ?: $legacy->salesLabel, $local->salesUserId ?: $local->salesLabel],
            'kavling' => [$legacy->kavlingId ?: $legacy->kavlingLabel, $local->kavlingId ?: $local->kavlingLabel],
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

    private function row(string $status, ?ConsumerComparisonRecord $legacy, ?ConsumerComparisonRecord $local, array $mismatches, array $notes): array
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
            'notes' => array_values(array_unique($notes)),
        ];
    }
}
