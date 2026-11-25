<?php

namespace App\Data;

use App\Models\Branch;

final readonly class BranchParityReport
{
    public function __construct(
        public Branch $branch,
        public string $status,
        public array $summary,
        public array $metrics,
        public array $differences,
    ) {}

    public function toArray(): array
    {
        return [
            'branch_id' => $this->branch->id,
            'branch_code' => $this->branch->code,
            'status' => $this->status,
            'summary' => $this->summary,
            'metrics' => $this->metrics,
            'differences' => $this->differences,
        ];
    }
}
