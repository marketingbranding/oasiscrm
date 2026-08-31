<?php

namespace App\ValueObjects;

final readonly class ResolvedSalesLeadSpreadsheetContract
{
    /**
     * @param  array<string, int>  $headerMap
     * @param  array<string, string>  $resolvedHeaders
     * @param  list<string>  $formulaOwnedHeaders
     * @param  array<string, list<string>>  $validationOptions
     */
    public function __construct(
        public string $spreadsheetId,
        public SalesLeadSheetDefinition $definition,
        public int $sheetId,
        public array $headers,
        public array $headerMap,
        public array $formulaOwnedHeaders,
        public int $templateRowNumber,
        public array $validationOptions = [],
        public array $resolvedHeaders = [],
        public array $columnMetadata = [],
    ) {}

    public function actualHeader(string $canonicalHeader): string
    {
        return $this->resolvedHeaders[$canonicalHeader] ?? $canonicalHeader;
    }
}
