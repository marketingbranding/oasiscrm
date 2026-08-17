<?php

namespace App\Services;

use App\Data\ConsumerComparisonResult;

final class ConsumerReadinessService
{
    public function summarize(ConsumerComparisonResult $comparison): array
    {
        $summary = $comparison->summary;
        $coverage = $comparison->coverage;
        $legacyTotal = $summary['total_legacy'];
        $localTotal = $summary['total_local'];
        $linked = $coverage['linked'];
        $linkCoverage = $coverage['link_coverage_percent'];
        $exactCoverage = $coverage['exact_match_percent'];
        $mismatchLimit = max(1, (int) ceil(max(1, $linked) * 0.05));

        $status = 'NOT_READY';
        if ($legacyTotal > 0 && $localTotal > 0 && $summary['ambiguous'] === 0 && $summary['legacy_only'] === 0 && $summary['local_only'] === 0 && $linkCoverage >= 95 && $exactCoverage >= 95 && $summary['mismatch'] <= $mismatchLimit) {
            $status = 'PILOT_CANDIDATE';
        } elseif ($legacyTotal > 0 && $localTotal > 0 && $summary['ambiguous'] === 0 && $linkCoverage >= 80) {
            $status = 'REVIEW';
        }

        $recommendations = [];
        if ($legacyTotal === 0 || $localTotal === 0 || $linkCoverage < 80) {
            $recommendations[] = 'Masih ada data yang belum cukup terhubung untuk pilot.';
        }
        if ($summary['ambiguous'] > 0) {
            $recommendations[] = 'Ada identity ambiguity yang harus diperiksa.';
        }
        foreach (['stage' => 'stage', 'bank' => 'bank', 'phone' => 'phone'] as $field => $label) {
            if (($comparison->fieldMismatches[$field] ?? 0) > 0) {
                $recommendations[] = 'Sebagian data masih mismatch '.$label.'.';
            }
        }
        if ($status === 'PILOT_CANDIDATE') {
            $recommendations[] = 'Coverage tinggi dan cocok untuk pilot terbatas, bukan cutover produksi.';
        }
        if ($recommendations === []) {
            $recommendations[] = 'Lakukan review manual sebelum keputusan pilot.';
        }

        return [
            'status' => $status,
            'legacy_total' => $legacyTotal,
            'local_total' => $localTotal,
            'linked_total' => $linked,
            'exact_match' => $summary['exact_match'],
            'mismatch' => $summary['mismatch'],
            'legacy_only' => $summary['legacy_only'],
            'local_only' => $summary['local_only'],
            'ambiguous' => $summary['ambiguous'],
            'link_coverage_percent' => $linkCoverage,
            'exact_match_percent' => $exactCoverage,
            'field_mismatches' => $comparison->fieldMismatches,
            'recommendations' => $recommendations,
        ];
    }
}

// Status is pilot guidance only; it never changes read source or writes data.
