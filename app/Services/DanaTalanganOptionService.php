<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\DatabaseSheetRecord;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DanaTalanganOptionService
{
    public function __construct(
        private GoogleSheetsApiService $googleSheets,
        private readonly ProjectIdentityResolver $projects,
    ) {}

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

        $project = $this->projects->resolveExactOrNull($branch, $projectName);
        if ($project === null) {
            return [];
        }
        $projectKeys = array_map(fn (string $label) => $this->normalizeProject($label), $this->projects->labels($project));
        $matchedGroups = array_keys(array_filter($groups, fn ($codes, $dataProject) => in_array($this->normalizeProject($dataProject), $projectKeys, true), ARRAY_FILTER_USE_BOTH));

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
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? '');
    }

    private function normalizeCode(string $value): string
    {
        return mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($value)));
    }
}
