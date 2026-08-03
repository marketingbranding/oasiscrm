<?php

namespace App\ValueObjects;

final readonly class GoogleSheetsAppendResult
{
    public function __construct(
        public string $updatedRange,
        public int $rowNumber,
    ) {}
}
