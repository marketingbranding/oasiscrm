<?php

namespace App\Data;

final readonly class ConsumerComparisonResult
{
    public function __construct(
        public array $rows,
        public array $summary,
        public array $fieldMismatches,
        public array $coverage,
    ) {}
}
