<?php

namespace App\Services;

use App\Models\Branch;
use App\ValueObjects\ResolvedSalesLeadSpreadsheetContract;
use App\ValueObjects\SalesLeadSheetDefinition;
use Illuminate\Support\Facades\Cache;

class SalesLeadSheetOptionService
{
    public function __construct(private readonly SalesLeadSpreadsheetContract $contracts) {}

    /** @return array{promo: list<string>, source: list<string>, channel: list<string>, activity: list<string>, project: list<string>, sales: list<string>, status: list<string>} */
    public function forBranch(Branch $branch): array
    {
        $contract = $this->contract($branch);

        return [
            'promo' => $contract->validationOptions['id_promo'] ?? [],
            'source' => $contract->validationOptions['sumber_lead'] ?? [],
            'channel' => $contract->validationOptions['kanal_masuk'] ?? [],
            'activity' => $contract->validationOptions['aktivitas_lead'] ?? [],
            'project' => $contract->validationOptions['proyek'] ?? [],
            'sales' => $contract->validationOptions['sales_pic'] ?? [],
            'status' => $contract->validationOptions['status_lead'] ?? [],
        ];
    }

    public function contract(Branch $branch): ResolvedSalesLeadSpreadsheetContract
    {
        $key = 'sales-lead-sheet-options:v2:'.$branch->id.':'.hash('sha256', (string) $branch->sheet_id);

        $cached = Cache::remember($key, now()->addSeconds(60), fn () => $this->toCachePayload($this->contracts->resolveForBranch($branch, 'lead')));

        if (! is_array($cached)) {
            $resolved = $this->contracts->resolveForBranch($branch, 'lead');
            Cache::put($key, $this->toCachePayload($resolved), now()->addSeconds(60));

            return $resolved;
        }

        return $this->fromCachePayload($cached);
    }

    /** @return array<string, mixed> */
    private function toCachePayload(ResolvedSalesLeadSpreadsheetContract $contract): array
    {
        return [
            'spreadsheet_id' => $contract->spreadsheetId,
            'sheet_id' => $contract->sheetId,
            'headers' => $contract->headers,
            'header_map' => $contract->headerMap,
            'formula_owned_headers' => $contract->formulaOwnedHeaders,
            'template_row_number' => $contract->templateRowNumber,
            'validation_options' => $contract->validationOptions,
            'resolved_headers' => $contract->resolvedHeaders,
            'definition' => [
                'sheet_name' => $contract->definition->sheetName,
                'required_headers' => $contract->definition->requiredHeaders,
                'formula_owned_headers' => $contract->definition->formulaOwnedHeaders,
                'validations' => $contract->definition->validations,
                'header_aliases' => $contract->definition->headerAliases,
            ],
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function fromCachePayload(array $payload): ResolvedSalesLeadSpreadsheetContract
    {
        $definition = $payload['definition'] ?? [];

        return new ResolvedSalesLeadSpreadsheetContract(
            (string) ($payload['spreadsheet_id'] ?? ''),
            new SalesLeadSheetDefinition(
                (string) ($definition['sheet_name'] ?? 'lead'),
                (array) ($definition['required_headers'] ?? []),
                (array) ($definition['formula_owned_headers'] ?? []),
                (array) ($definition['validations'] ?? []),
                (array) ($definition['header_aliases'] ?? []),
            ),
            (int) ($payload['sheet_id'] ?? 0),
            (array) ($payload['headers'] ?? []),
            (array) ($payload['header_map'] ?? []),
            (array) ($payload['formula_owned_headers'] ?? []),
            (int) ($payload['template_row_number'] ?? 0),
            (array) ($payload['validation_options'] ?? []),
            (array) ($payload['resolved_headers'] ?? []),
        );
    }

    public function exactOption(array $options, ?string $value): ?string
    {
        $normalized = $this->normalize($value);
        if ($normalized === '') {
            return null;
        }
        $matches = array_values(array_filter($options, fn (string $option) => $this->normalize($option) === $normalized));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '');
    }
}
