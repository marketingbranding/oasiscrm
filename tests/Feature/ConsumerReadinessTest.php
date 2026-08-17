<?php

namespace Tests\Feature;

use App\Data\ConsumerComparisonResult;
use App\Services\ConsumerReadinessService;
use Tests\TestCase;

class ConsumerReadinessTest extends TestCase
{
    public function test_zero_local_is_not_ready(): void
    {
        $result = app(ConsumerReadinessService::class)->summarize($this->comparisonResult(['total_legacy' => 2, 'total_local' => 0], ['linked' => 0, 'link_coverage_percent' => 0, 'exact_match_percent' => 0]));

        $this->assertSame('NOT_READY', $result['status']);
    }

    public function test_ambiguous_is_not_ready(): void
    {
        $result = app(ConsumerReadinessService::class)->summarize($this->comparisonResult(['total_legacy' => 10, 'total_local' => 10, 'ambiguous' => 1], ['linked' => 9, 'link_coverage_percent' => 90, 'exact_match_percent' => 100]));

        $this->assertSame('NOT_READY', $result['status']);
    }

    public function test_high_coverage_mismatch_is_review(): void
    {
        $result = app(ConsumerReadinessService::class)->summarize($this->comparisonResult(['total_legacy' => 10, 'total_local' => 10, 'mismatch' => 1], ['linked' => 10, 'link_coverage_percent' => 100, 'exact_match_percent' => 90], ['stage' => 1]));

        $this->assertSame('REVIEW', $result['status']);
        $this->assertContains('Sebagian data masih mismatch stage.', $result['recommendations']);
    }

    public function test_high_coverage_exact_data_is_pilot_candidate(): void
    {
        $result = app(ConsumerReadinessService::class)->summarize($this->comparisonResult(['total_legacy' => 20, 'total_local' => 20], ['linked' => 20, 'link_coverage_percent' => 100, 'exact_match_percent' => 100]));

        $this->assertSame('PILOT_CANDIDATE', $result['status']);
    }

    public function test_field_breakdown_is_preserved(): void
    {
        $fields = ['name' => 0, 'phone' => 1, 'sales' => 0, 'kavling' => 0, 'stage' => 2, 'booking_date' => 0, 'akad_date' => 0, 'bank' => 1, 'bank_status' => 0];
        $result = app(ConsumerReadinessService::class)->summarize($this->comparisonResult([], [], $fields));

        $this->assertSame(1, $result['field_mismatches']['phone']);
        $this->assertSame(2, $result['field_mismatches']['stage']);
        $this->assertSame(1, $result['field_mismatches']['bank']);
    }

    private function comparisonResult(array $summary = [], array $coverage = [], array $fields = []): ConsumerComparisonResult
    {
        return new ConsumerComparisonResult(
            rows: [],
            summary: array_merge(['total_legacy' => 0, 'total_local' => 0, 'matched' => 0, 'exact_match' => 0, 'mismatch' => 0, 'legacy_only' => 0, 'local_only' => 0, 'ambiguous' => 0], $summary),
            fieldMismatches: array_merge(['name' => 0, 'phone' => 0, 'sales' => 0, 'kavling' => 0, 'stage' => 0, 'booking_date' => 0, 'akad_date' => 0, 'bank' => 0, 'bank_status' => 0], $fields),
            coverage: array_merge(['linked' => 0, 'link_coverage_percent' => 0, 'exact_match_percent' => 0], $coverage),
        );
    }
}

// Readiness tests derive from Phase 3 result contract; no source writes or matching rules are duplicated.
