<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DashboardConsumerMetricsService
{
    public function __construct(private readonly KonsumenProgressReadService $reader) {}

    /** @return array{metrics: array<string, array{label:string,count:int}>, fallback_used: bool}|null */
    public function local(?int $branchId, array $branchIds): ?array
    {
        if (config('oasis.dashboard_consumer_read_source', 'legacy') !== 'local') {
            return null;
        }

        try {
            $metrics = array_fill_keys(array_keys(KonsumenPipelineService::STAGES), 0);
            $branches = Branch::query()->whereIn('id', $branchIds)->when($branchId, fn ($query) => $query->whereKey($branchId))->get();
            foreach ($branches as $branch) {
                $read = $this->reader->read($branch, null, 'local');
                if ($read['fallback_used']) {
                    return null;
                }
                foreach ($read['pipeline'] as $stage => $items) {
                    $metrics[$stage] += count($items);
                }
            }

            return ['metrics' => collect($metrics)->map(fn ($count, $stage) => ['label' => KonsumenPipelineService::STAGES[$stage], 'count' => $count])->all(), 'fallback_used' => false];
        } catch (Throwable $exception) {
            Log::warning('Dashboard local consumer metrics fell back to legacy.', [
                'branch_id' => $branchId,
                'metric_group' => 'konsumen_progress',
                'exception' => get_class($exception),
                'reason' => substr(preg_replace('/[\r\n]+/', ' ', $exception->getMessage()), 0, 200),
            ]);

            return null;
        }
    }
}

// Local zero is valid; null means technical failure and lets controller retain legacy metrics.
