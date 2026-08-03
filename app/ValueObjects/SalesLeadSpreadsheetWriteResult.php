<?php

namespace App\ValueObjects;

final readonly class SalesLeadSpreadsheetWriteResult
{
    public function __construct(
        public string $spreadsheetId,
        public string $sheetName,
        public int $rowNumber,
        public string $syncId,
    ) {}
}
