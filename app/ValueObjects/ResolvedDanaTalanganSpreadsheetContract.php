<?php

namespace App\ValueObjects;

final readonly class ResolvedDanaTalanganSpreadsheetContract
{
    public function __construct(
        public string $spreadsheetId,
        public string $sheetName,
        public int $sheetId,
        public array $headers,
        public array $metadata,
        public string $hash,
    ) {}
}
