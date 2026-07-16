<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\DanaTalangan;
use App\Models\DatabaseSheetRecord;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DanaTalanganOptionService
{
    public function __construct(private GoogleSheetsApiService $googleSheets) {}

    public function kavlings(Branch $branch, string $projectName): array
    {
        $rows = $this->dataKavRows($branch);
        if (empty($rows)) {
            return [];
        }

        $groups = [];
        foreach ($rows as $row) {
            $dataProject = trim((string) ($row['proyek'] ?? ''));
            $code = trim((string) ($row['kode_kavling'] ?? ''));
            if ($dataProject !== '' && $code !== '') {
                $groups[$dataProject][$code] = $code;
            }
        }

        $projectKey = $this->normalizeProject($projectName);
        $matchedGroups = array_keys(array_filter($groups, function ($codes, $dataProject) use ($projectKey) {
            $dataKey = $this->normalizeProject($dataProject);

            return $dataKey !== '' && ($dataKey === $projectKey || str_contains($dataKey, $projectKey) || str_contains($projectKey, $dataKey));
        }, ARRAY_FILTER_USE_BOTH));

        if (empty($matchedGroups)) {
            $knownCodes = DanaTalangan::where('branch_id', $branch->id)
                ->whereNotNull('kav')
                ->get(['project_name', 'kav'])
                ->filter(function ($record) use ($projectKey) {
                    $recordKey = $this->normalizeProject($record->project_name ?? '');

                    return $recordKey === $projectKey || str_contains($recordKey, $projectKey) || str_contains($projectKey, $recordKey);
                })
                ->pluck('kav')
                ->map(fn ($code) => $this->normalizeCode($code))
                ->filter()
                ->unique()
                ->all();
            $scores = [];
            foreach ($groups as $dataProject => $codes) {
                $normalizedCodes = array_map(fn ($code) => $this->normalizeCode($code), array_keys($codes));
                $scores[$dataProject] = count(array_intersect($knownCodes, $normalizedCodes));
            }
            $bestScore = empty($scores) ? 0 : max($scores);
            if ($bestScore > 0) {
                $matchedGroups = array_keys(array_filter($scores, fn ($score) => $score === $bestScore));
            }
        }

        if (empty($matchedGroups) && count($groups) === 1) {
            $matchedGroups = [array_key_first($groups)];
        }

        $options = [];
        foreach ($matchedGroups as $dataProject) {
            foreach ($groups[$dataProject] ?? [] as $code) {
                $options[$code] = $code;
            }
        }
        uksort($options, 'strnatcasecmp');

        return array_values($options);
    }

    public function isValidKavling(Branch $branch, string $projectName, ?string $kavling): bool
    {
        if ($kavling === null || trim($kavling) === '') {
            return true;
        }

        $needle = $this->normalizeCode($kavling);

        return collect($this->kavlings($branch, $projectName))
            ->contains(fn ($code) => $this->normalizeCode($code) === $needle);
    }

    private function dataKavRows(Branch $branch): array
    {
        $cachedRows = DatabaseSheetRecord::where('branch_id', $branch->id)
            ->where('sheet_name', 'data_kav')
            ->orderBy('row_number')
            ->pluck('row_data')
            ->all();
        if (! empty($cachedRows)) {
            return $cachedRows;
        }

        if (! $branch->sheet_id) {
            return [];
        }

        return Cache::remember("dana-talangan:data-kav:{$branch->id}", now()->addMinutes(10), function () use ($branch) {
            try {
                $rows = $this->googleSheets->batchGetRaw($branch->sheet_id, ['data_kav!A:F'])['data_kav'] ?? [];
                $headers = array_map(fn ($header) => mb_strtolower(trim((string) $header)), array_shift($rows) ?? []);

                return array_map(function ($row) use ($headers) {
                    $row = array_pad($row, count($headers), '');

                    return array_combine($headers, array_slice($row, 0, count($headers))) ?: [];
                }, $rows);
            } catch (Throwable) {
                return [];
            }
        });
    }

    private function normalizeProject(string $value): string
    {
        $value = mb_strtolower($value);
        $value = str_replace(['marison', 'regency'], '', $value);

        return preg_replace('/[^a-z0-9]+/', '', $value);
    }

    private function normalizeCode(string $value): string
    {
        return mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($value)));
    }
}
