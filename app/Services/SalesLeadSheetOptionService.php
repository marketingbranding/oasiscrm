<?php

namespace App\Services;

use App\Models\Branch;
use App\ValueObjects\ResolvedSalesLeadSpreadsheetContract;
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
            'source' => $contract->validationOptions['source'] ?? [],
            'channel' => $contract->validationOptions['platform'] ?? [],
            'activity' => $contract->validationOptions['campaign_name'] ?? [],
            'project' => $contract->validationOptions['proyek'] ?? [],
            'sales' => $contract->validationOptions['sales_pic'] ?? [],
            'status' => $contract->validationOptions['status_lead'] ?? [],
        ];
    }

    public function contract(Branch $branch): ResolvedSalesLeadSpreadsheetContract
    {
        $key = 'sales-lead-sheet-options:'.$branch->id.':'.hash('sha256', (string) $branch->sheet_id);

        return Cache::remember($key, now()->addSeconds(60), fn () => $this->contracts->resolveForBranch($branch, 'lead'));
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
