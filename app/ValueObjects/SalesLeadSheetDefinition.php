<?php

namespace App\ValueObjects;

final readonly class SalesLeadSheetDefinition
{
    /**
     * @param  list<string>  $requiredHeaders
     * @param  list<string>  $formulaOwnedHeaders
     * @param  array<string, array{type: string, strict?: bool, values?: list<string>}>  $validations
     */
    public function __construct(
        public string $sheetName,
        public array $requiredHeaders,
        public array $formulaOwnedHeaders = [],
        public array $validations = [],
    ) {}
}
